<?php
namespace GraphQL\Executor;

use GraphQL\Error;
use GraphQL\FormattedError;
use GraphQL\Language\Parser;
use GraphQL\Language\SourceLocation;
use GraphQL\Schema;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;
use GraphQL\Type\Definition\UnionType;
use GraphQL\Utils;

class ExecutorTest extends \PHPUnit_Framework_TestCase
{
    // Execute: Handles basic execution tasks
    public function testExecutesArbitraryCode()
    {
        $deepData = null;
        $data = [
            'a' => function () { return 'Apple';},
            'b' => function () {return 'Banana';},
            'c' => function () {return 'Cookie';},
            'd' => function () {return 'Donut';},
            'e' => function () {return 'Egg';},
            'f' => 'Fish',
            'pic' => function ($size = 50) {
                return 'Pic of size: ' . $size;
            },
            'promise' => function() use (&$data) {
                return $data;
            },
            'deep' => function () use (&$deepData) {
                return $deepData;
            }
        ];

        $deepData = [
            'a' => function () { return 'Already Been Done'; },
            'b' => function () { return 'Boring'; },
            'c' => function () {
                return ['Contrived', null, 'Confusing'];
            },
            'deeper' => function () use ($data) {
                return [$data, null, $data];
            }
        ];


        $doc = '
      query Example($size: Int) {
        a,
        b,
        x: c
        ...c
        f
        ...on DataType {
          pic(size: $size)
          promise {
            a
          }
        }
        deep {
          a
          b
          c
          deeper {
            a
            b
          }
        }
      }

      fragment c on DataType {
        d
        e
      }
    ';

        $ast = Parser::parse($doc);
        $expected = [
            'data' => [
                'a' => 'Apple',
                'b' => 'Banana',
                'x' => 'Cookie',
                'd' => 'Donut',
                'e' => 'Egg',
                'f' => 'Fish',
                'pic' => 'Pic of size: 100',
                'promise' => [
                    'a' => 'Apple'
                ],
                'deep' => [
                    'a' => 'Already Been Done',
                    'b' => 'Boring',
                    'c' => [ 'Contrived', null, 'Confusing' ],
                    'deeper' => [
                        [ 'a' => 'Apple', 'b' => 'Banana' ],
                        null,
                        [ 'a' => 'Apple', 'b' => 'Banana' ]
                    ]
                ]
            ]
        ];

        $deepDataType = null;
        $dataType = new ObjectType([
            'name' => 'DataType',
            'fields' => [
                'a' => [ 'type' => Type::string() ],
                'b' => [ 'type' => Type::string() ],
                'c' => [ 'type' => Type::string() ],
                'd' => [ 'type' => Type::string() ],
                'e' => [ 'type' => Type::string() ],
                'f' => [ 'type' => Type::string() ],
                'pic' => [
                    'args' => [ 'size' => ['type' => Type::int() ] ],
                    'type' => Type::string(),
                    'resolve' => function($obj, $args) { return $obj['pic']($args['size']); }
                ],
                'promise' => ['type' => function() use (&$dataType) {return $dataType;}],
                'deep' => [ 'type' => function() use(&$deepDataType) {return $deepDataType; }],
            ]
        ]);

        $deepDataType = new ObjectType([
            'name' => 'DeepDataType',
            'fields' => [
                'a' => [ 'type' => Type::string() ],
                'b' => [ 'type' => Type::string() ],
                'c' => [ 'type' => Type::listOf(Type::string()) ],
                'deeper' => [ 'type' => Type::listOf($dataType) ]
            ]
        ]);
        $schema = new Schema($dataType);

        $this->assertEquals($expected, Executor::execute($schema, $ast, $data, ['size' => 100], 'Example')->toArray());
    }

    public function testMergesParallelFragments()
    {
        $ast = Parser::parse('
      { a, ...FragOne, ...FragTwo }

      fragment FragOne on Type {
        b
        deep { b, deeper: deep { b } }
      }

      fragment FragTwo on Type {
        c
        deep { c, deeper: deep { c } }
      }
        ');

        $Type = new ObjectType([
            'name' => 'Type',
            'fields' => [
                'a' => ['type' => Type::string(), 'resolve' => function () {
                    return 'Apple';
                }],
                'b' => ['type' => Type::string(), 'resolve' => function () {
                    return 'Banana';
                }],
                'c' => ['type' => Type::string(), 'resolve' => function () {
                    return 'Cherry';
                }],
                'deep' => [
                    'type' => function () use (&$Type) {
                        return $Type;
                    },
                    'resolve' => function () {
                        return [];
                    }
                ]
            ]
        ]);
        $schema = new Schema($Type);
        $expected = [
            'data' => [
                'a' => 'Apple',
                'b' => 'Banana',
                'c' => 'Cherry',
                'deep' => [
                    'b' => 'Banana',
                    'c' => 'Cherry',
                    'deeper' => [
                        'b' => 'Banana',
                        'c' => 'Cherry'
                    ]
                ]
            ]
        ];

        $this->assertEquals($expected, Executor::execute($schema, $ast)->toArray());
    }

    public function testThreadsContextCorrectly()
    {
        // threads context correctly
        $doc = 'query Example { a }';

        $gotHere = false;

        $data = [
            'contextThing' => 'thing',
        ];

        $ast = Parser::parse($doc);
        $schema = new Schema(new ObjectType([
            'name' => 'Type',
            'fields' => [
                'a' => [
                    'type' => Type::string(),
                    'resolve' => function ($context) use ($doc, &$gotHere) {
                        $this->assertEquals('thing', $context['contextThing']);
                        $gotHere = true;
                    }
                ]
            ]
        ]));

        Executor::execute($schema, $ast, $data, [], 'Example');
        $this->assertEquals(true, $gotHere);
    }

    public function testCorrectlyThreadsArguments()
    {
        $doc = '
      query Example {
        b(numArg: 123, stringArg: "foo")
      }
        ';

        $gotHere = false;

        $docAst = Parser::parse($doc);
        $schema = new Schema(new ObjectType([
            'name' => 'Type',
            'fields' => [
                'b' => [
                    'args' => [
                        'numArg' => ['type' => Type::int()],
                        'stringArg' => ['type' => Type::string()]
                    ],
                    'type' => Type::string(),
                    'resolve' => function ($_, $args) use (&$gotHere) {
                        $this->assertEquals(123, $args['numArg']);
                        $this->assertEquals('foo', $args['stringArg']);
                        $gotHere = true;
                    }
                ]
            ]
        ]));
        Executor::execute($schema, $docAst, null, [], 'Example');
        $this->assertSame($gotHere, true);
    }

    public function testNullsOutErrorSubtrees()
    {
        $doc = '{
      sync,
      syncError,
      syncRawError,
      async,
      asyncReject,
      asyncError
        }';

        $data = [
            'sync' => function () {
                return 'sync';
            },
            'syncError' => function () {
                throw new Error('Error getting syncError');
            },
            'syncRawError' => function() {
                throw new \Exception('Error getting syncRawError');
            },
            // Following are inherited from JS reference implementation, but make no sense in this PHP impl
            // leaving them just to simplify migrations from newer js versions
            'async' => function() {
                return 'async';
            },
            'asyncReject' => function() {
                throw new \Exception('Error getting asyncReject');
            },
            'asyncError' => function() {
                throw new \Exception('Error getting asyncError');
            }
        ];

        $docAst = Parser::parse($doc);
        $schema = new Schema(new ObjectType([
            'name' => 'Type',
            'fields' => [
                'sync' => ['type' => Type::string()],
                'syncError' => ['type' => Type::string()],
                'syncRawError' => [ 'type' => Type::string() ],
                'async' => ['type' => Type::string()],
                'asyncReject' => ['type' => Type::string() ],
                'asyncError' => ['type' => Type::string()],
            ]
        ]));

        $expected = [
            'data' => [
                'sync' => 'sync',
                'syncError' => null,
                'syncRawError' => null,
                'async' => 'async',
                'asyncReject' => null,
                'asyncError' => null,
            ],
            'errors' => [
                FormattedError::create('Error getting syncError', [new SourceLocation(3, 7)]),
                FormattedError::create('Error getting syncRawError', [new SourceLocation(4, 7)]),
                FormattedError::create('Error getting asyncReject', [new SourceLocation(6, 7)]),
                FormattedError::create('Error getting asyncError', [new SourceLocation(7, 7)])
            ]
        ];

        $result = Executor::execute($schema, $docAst, $data);

        $this->assertEquals($expected, $result->toArray());
    }

    public function testUsesTheInlineOperationIfNoOperationIsProvided()
    {
        // uses the inline operation if no operation is provided
        $doc = '{ a }';
        $data = ['a' => 'b'];
        $ast = Parser::parse($doc);
        $schema = new Schema(new ObjectType([
            'name' => 'Type',
            'fields' => [
                'a' => ['type' => Type::string()],
            ]
        ]));

        $ex = Executor::execute($schema, $ast, $data);

        $this->assertEquals(['data' => ['a' => 'b']], $ex->toArray());
    }

    public function testUsesTheOnlyOperationIfNoOperationIsProvided()
    {
        $doc = 'query Example { a }';
        $data = [ 'a' => 'b' ];
        $ast = Parser::parse($doc);
        $schema = new Schema(new ObjectType([
            'name' => 'Type',
            'fields' => [
                'a' => [ 'type' => Type::string() ],
            ]
        ]));

        $ex = Executor::execute($schema, $ast, $data);
        $this->assertEquals(['data' => ['a' => 'b']], $ex->toArray());
    }

    public function testThrowsIfNoOperationIsProvidedWithMultipleOperations()
    {
        $doc = 'query Example { a } query OtherExample { a }';
        $data = [ 'a' => 'b' ];
        $ast = Parser::parse($doc);
        $schema = new Schema(new ObjectType([
            'name' => 'Type',
            'fields' => [
                'a' => [ 'type' => Type::string() ],
            ]
        ]));

        try {
            Executor::execute($schema, $ast, $data);
            $this->fail('Expected exception is not thrown');
        } catch (Error $err) {
            $this->assertEquals('Must provide operation name if query contains multiple operations.', $err->getMessage());
        }
    }

    public function testUsesTheQuerySchemaForQueries()
    {
        $doc = 'query Q { a } mutation M { c }';
        $data = ['a' => 'b', 'c' => 'd'];
        $ast = Parser::parse($doc);
        $schema = new Schema(
            new ObjectType([
                'name' => 'Q',
                'fields' => [
                    'a' => ['type' => Type::string()],
                ]
            ]),
            new ObjectType([
                'name' => 'M',
                'fields' => [
                    'c' => ['type' => Type::string()],
                ]
            ])
        );

        $queryResult = Executor::execute($schema, $ast, $data, [], 'Q');
        $this->assertEquals(['data' => ['a' => 'b']], $queryResult->toArray());
    }

    public function testUsesTheMutationSchemaForMutations()
    {
        $doc = 'query Q { a } mutation M { c }';
        $data = [ 'a' => 'b', 'c' => 'd' ];
        $ast = Parser::parse($doc);
        $schema = new Schema(
            new ObjectType([
                'name' => 'Q',
                'fields' => [
                    'a' => ['type' => Type::string()],
                ]
            ]),
            new ObjectType([
                'name' => 'M',
                'fields' => [
                    'c' => [ 'type' => Type::string() ],
                ]
            ])
        );
        $mutationResult = Executor::execute($schema, $ast, $data, [], 'M');
        $this->assertEquals(['data' => ['c' => 'd']], $mutationResult->toArray());
    }

    public function testAvoidsRecursion()
    {
        $doc = '
      query Q {
        a
        ...Frag
        ...Frag
      }

      fragment Frag on DataType {
        a,
        ...Frag
      }
        ';
        $data = ['a' => 'b'];
        $ast = Parser::parse($doc);
        $schema = new Schema(new ObjectType([
            'name' => 'Type',
            'fields' => [
                'a' => ['type' => Type::string()],
            ]
        ]));

        $queryResult = Executor::execute($schema, $ast, $data, [], 'Q');
        $this->assertEquals(['data' => ['a' => 'b']], $queryResult->toArray());
    }

    public function testDoesNotIncludeIllegalFieldsInOutput()
    {
        $doc = 'mutation M {
      thisIsIllegalDontIncludeMe
    }';
        $ast = Parser::parse($doc);
        $schema = new Schema(
            new ObjectType([
                'name' => 'Q',
                'fields' => [
                    'a' => ['type' => Type::string()],
                ]
            ]),
            new ObjectType([
                'name' => 'M',
                'fields' => [
                    'c' => ['type' => Type::string()],
                ]
            ])
        );
        $mutationResult = Executor::execute($schema, $ast);
        $this->assertEquals(['data' => []], $mutationResult->toArray());
    }

    public function testDoesNotIncludeArgumentsThatWereNotSet()
    {
        $schema = new Schema(
            new ObjectType([
                'name' => 'Type',
                'fields' => [
                    'field' => [
                        'type' => Type::string(),
                        'resolve' => function($data, $args) {return $args ? json_encode($args) : '';},
                        'args' => [
                            'a' => ['type' => Type::boolean()],
                            'b' => ['type' => Type::boolean()],
                            'c' => ['type' => Type::boolean()],
                            'd' => ['type' => Type::int()],
                            'e' => ['type' => Type::int()]
                        ]
                    ]
                ]
            ])
        );

        $query = Parser::parse('{ field(a: true, c: false, e: 0) }');
        $result = Executor::execute($schema, $query);
        $expected = [
            'data' => [
                'field' => '{"a":true,"c":false,"e":0}'
            ]
        ];

        $this->assertEquals($expected, $result->toArray());
    }

    public function testExecutesMapCallbacksIfSet()
    {
        $fooData = [
            ['field' => '1'],
            ['field' => null],
            null,
            ['field' => '4'],
        ];

        $foo = new ObjectType([
            'name' => 'Foo',
            'fields' => [
                'field' => [
                    'type' => Type::string(),
                    'map' => function($listOfFoo, $args, $resolveInfo) use ($fooData) {

                        return Utils::map($listOfFoo, function($fooData) use ($args, $resolveInfo) {
                            return json_encode([
                                'value' => $fooData['field'] === null ? null : $fooData['field'] . 'x',
                                'args' => $args,
                                'gotResolveInfo' => $resolveInfo instanceof ResolveInfo
                            ]);
                        });
                    },
                    'args' => [
                        'a' => ['type' => Type::boolean()],
                        'b' => ['type' => Type::boolean()],
                        'c' => ['type' => Type::int()]
                    ]
                ]
            ]
        ]);

        $bar = new ObjectType([
            'name' => 'Bar',
            'fields' => [
                'foo' => [
                    'type' => Type::listOf($foo),
                    'resolve' => function() use ($fooData) {
                        return $fooData;
                    }
                ]
            ]
        ]);

        $schema = new Schema($bar);

        $query = Parser::parse('{ foo { field(a: true, c: 0) } }');
        $result = Executor::execute($schema, $query);

        $expected = [
            'data' => [
                'foo' => [
                    ['field' => '{"value":"1x","args":{"a":true,"c":0},"gotResolveInfo":true}'],
                    ['field' => '{"value":null,"args":{"a":true,"c":0},"gotResolveInfo":true}'],
                    null,
                    ['field' => '{"value":"4x","args":{"a":true,"c":0},"gotResolveInfo":true}'],
                ]
            ]
        ];

        $this->assertEquals($expected, $result->toArray());
    }

    public function testRespectsListsOfAbstractTypeWhenResolvingViaMap()
    {
        $type1 = null;
        $type2 = null;
        $type3 = null;

        $resolveType = function($value) use (&$type1, &$type2, &$type3) {
            switch ($value['type']) {
                case 'Type1':
                    return $type1;
                case 'Type2':
                    return $type2;
                case 'Type3':
                default:
                    return $type3;
            }
        };

        $mapValues = function($typeValues, $args) {
            return Utils::map($typeValues, function($value) use ($args) {
                if (array_key_exists('foo', $value)) {
                    return json_encode([
                        'value' => $value,
                        'args' => $args,
                    ]);
                } else {
                    return null;
                }
            });
        };

        $interface = new InterfaceType([
            'name' => 'SomeInterface',
            'fields' => [
                'foo' => ['type' => Type::string()],
            ],
            'resolveType' => $resolveType
        ]);

        $type1 = new ObjectType([
            'name' => 'Type1',
            'fields' => [
                'foo' => [
                    'type' => Type::string(),
                    'map' => $mapValues
                ]
            ],
            'interfaces' => [$interface]
        ]);

        $type2 = new ObjectType([
            'name' => 'Type2',
            'fields' => [
                'foo' => [
                    'type' => Type::string(),
                    'map' => $mapValues
                ]
            ],
            'interfaces' => [$interface]
        ]);

        $type3 = new ObjectType([
            'name' => 'Type3',
            'fields' => [
                'bar' => [
                    'type' => Type::listOf(Type::string()),
                    'map' => function($type3Values, $args) {
                        return Utils::map($type3Values, function($value) use ($args) {
                            return [
                                json_encode([
                                    'value' => $value,
                                    'args' => $args
                                ])
                            ];
                        });
                    }
                ]
            ]
        ]);

        $union = new UnionType([
            'name' => 'SomeUnion',
            'types' => [$type1, $type3],
            'resolveType' => $resolveType
        ]);

        $complexType = new ObjectType([
            'name' => 'ComplexType',
            'fields' => [
                'iface' => [
                    'type' => $interface
                ],
                'ifaceList' => [
                    'type' => Type::listOf($interface)
                ],
                'union' => [
                    'type' => $union
                ],
                'unionList' => [
                    'type' => Type::listOf($union)
                ]
            ]
        ]);

        $type1values = [
            ['type' => 'Type1', 'foo' => 'str1'],
            ['type' => 'Type1'],
            ['type' => 'Type1', 'foo' => null],
        ];

        $type2values = [
            ['type' => 'Type2', 'foo' => 'str1'],
            ['type' => 'Type2', 'foo' => null],
            ['type' => 'Type2'],
        ];

        $type3values = [
            ['type' => 'Type3', 'bar' => ['str1', 'str2']],
            ['type' => 'Type3', 'bar' => null],
        ];

        $complexTypeValues = [
            'iface' => $type1values[0],
            'ifaceList' => array_merge($type1values, $type2values),
            'union' => $type3values[0],
            'unionList' => array_merge($type1values, $type3values)
        ];

        $expected = [
            'data' => [
                'test' => [
                    'iface' => ['foo' => json_encode(['value' => $type1values[0], 'args' => []])],
                    'ifaceList' => [
                        ['foo' => '{"value":{"type":"Type1","foo":"str1"},"args":[]}'],
                        ['foo' => null],
                        ['foo' => '{"value":{"type":"Type1","foo":null},"args":[]}'],
                        ['foo' => '{"value":{"type":"Type2","foo":"str1"},"args":[]}'],
                        ['foo' => '{"value":{"type":"Type2","foo":null},"args":[]}'],
                        ['foo' => null],
                    ],
                    'union' => [
                        'bar' => ['{"value":{"type":"Type3","bar":["str1","str2"]},"args":[]}']
                    ],
                    'unionList' => [
                        ['foo' => '{"value":{"type":"Type1","foo":"str1"},"args":[]}'],
                        ['foo' => null],
                        ['foo' => '{"value":{"type":"Type1","foo":null},"args":[]}'],
                        ['bar' => ['{"value":{"type":"Type3","bar":["str1","str2"]},"args":[]}']],
                        ['bar' => ['{"value":{"type":"Type3","bar":null},"args":[]}']],
                    ]
                ]
            ]
        ];

        $schema = new Schema(new ObjectType([
            'name' => 'Query',
            'fields' => [
                'test' => [
                    'type' => $complexType,
                    'resolve' => function() use ($complexTypeValues) {
                        return $complexTypeValues;
                    }
                ]
            ]
        ]));

        $query = '{
            test {
                iface{foo},
                ifaceList{foo}
                union {
                    ... on Type1 {
                        foo
                    }
                    ... on Type3 {
                        bar
                    }
                }
                unionList {
                    ... on Type1 {
                        foo
                    }
                    ... on Type3 {
                        bar
                    }
                }
            }
        }';

        $query = Parser::parse($query);
        $result = Executor::execute($schema, $query);

        $this->assertEquals($expected, $result->toArray());
    }
}
