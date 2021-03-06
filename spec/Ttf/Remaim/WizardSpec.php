<?php
/**
 * PhpSpec spec for the Redmine to Maniphest Importer
 *
 * @author David Raison <david@tentwentyfour.lu>
 */

namespace spec\Ttf\Remaim;

use PhpSpec\ObjectBehavior;
use Prophecy\Argument;

use Mockery as m;
use phpmock\mockery\PHPMockery;

use Pimple\Container;

use Redmine\Client;
use Redmine\Api\Project;
use Redmine\Api\Issue;
use Redmine\Api\Membership;
use Redmine\Api\CustomField;

use Ttf\Remaim\Wizard;
use Ttf\Remaim\Journal;
use Ttf\Remaim\Facade\Redmine as Facade;

require_once '/usr/share/libphutil/src/__phutil_library_init__.php';

class WizardSpec extends ObjectBehavior
{

    private $container;

    /**
     * While simple objects use Prophecy by default,
     * we using Mockery for mocking ConduitClient, which is marked as final.
     * See http://docs.mockery.io/en/latest/reference/index.html for Reference.
     *
     * "The class \ConduitClient is marked final and its methods cannot be replaced. Classes marked final can be passed in to \Mockery::mock() as instantiated objects to create a partial mock, but only if the mock is not subject to type hinting checks.
     *
     * We're also using mockery on the services inside our ServiceContainer. With prophecy, we would always get the following Exception when trying to stub it:
     * "Cannot use object of type Prophecy\Prophecy\MethodProphecy as array in src/Wizard.php on line 197"
     *
     * @return void
     */
    public function let(
        Client $redmine,
        Project $project,
        CustomField $custom_fields
    ) {
        date_default_timezone_set('UTC');

        $container = new Container();
        $container['redmine'] = function ($c) {
            return m::mock('Facade');
        };

        $container['journal'] = $container->factory(function ($c) {
            return m::mock('Journal');
        });

        $container['config'] = [
            'redmine' => [
                'user' => 'Hank',
                'password' => 'ImNotMoody',
                'protocol' => 'https',
            ],
            'phabricator' => [
                'host' => 'https://localhost',
            ],
            'priority_map' => [
                'Urgent' => 100,
                'Normal' => 50,
                'Low' => 25
            ]
        ];

        // $redmine->api('project')->willReturn($project);
        // $project->listing()->willReturn(['some' => 'array']);

        // Proxied partial mock, see http://docs.mockery.io/en/latest/reference/partial_mocks.html#proxied-partial-mock and method documentation above.
        $container['conduit'] = function ($c) use ($container) {
            $conduit = m::mock(new \ConduitClient($container['config']['phabricator']['host']));
            $conduit
            ->shouldReceive('callMethodSynchronous')
            ->with('maniphest.querystatuses', [])
            ->once()
            ->andReturn(['statusMap' => [
                'open' => 'Open',
                'resolved' => 'Resolved',
            ]]);
            return $conduit;
        };

        $this->beConstructedWith($container);
        $this->container = $container;
    }

    public function letGo()
    {
        m::close();
    }

    function it_is_initializable()
    {
        $this->shouldHaveType(Wizard::class);
    }

    function it_presents_a_summary_before_initiating_the_migration()
    {
        $details = [
            'project' => [
                'name' => 'Redmine Project'
            ]
        ];

        $this->container['redmine'] = $this->container->extend('redmine', function ($redmine, $c) use ($details) {
            $redmine->shouldReceive('getProjectDetails')->with(34)->once()->andReturn($details);
            return $redmine;
        });

        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('y');

        $phabricator_project = [
            'name' => 'Phabricator Project',
            'id' => 78,
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];
        $tasks = [
            'total_count' => [10],
        ];
        ob_start();
        $this->presentSummary(
            34,
            $phabricator_project,
            $tasks,
            $policies
        )->shouldReturn(true);
        $output = ob_get_clean();
        expect($output)->toBe(PHP_EOL . PHP_EOL .
            '####################' . PHP_EOL .
            '# Pre-flight check #' . PHP_EOL .
            '####################' . PHP_EOL .
            'Redmine project named "Redmine Project" with ID 34.' . PHP_EOL .
            'Target phabricator project named "Phabricator Project" with ID 78.' . PHP_EOL .
            'View policy: PHID-foobar, Edit policy: PHID-barbaz' . PHP_EOL .
            '10 tickets to be migrated!' . PHP_EOL . PHP_EOL . 'OK to continue? [y/N]' . PHP_EOL .
            '> '
        );
    }

    function it_is_able_to_look_up_a_phabricator_project_by_its_id()
    {
        $lookup = [
            'ids' => [1]
        ];
        $project_array = [
            'phid' => 'test-phid',
            'name' => 'test-project-name',
        ];
        $query_result = [
            'data' => [$project_array],
        ];
        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('project.query', $lookup)
        ->once()
        ->andReturn($query_result);
        $this->findPhabricatorProject($lookup)->shouldReturn($project_array);
    }

    function it_returns_the_redmine_projects_id_and_name()
    {
        $project = [
            'id' => 5,
            'name' => 'Tests',
            'description' => 'Test has to ignore me'
        ];
        $this->representProject($project)->shouldReturn("[5 – Tests]\n");
    }

    function it_looks_up_group_projects()
    {
        $groups = [
            'data' => [
                [
                    'id' => 23,
                    'type' => 'PROJ',
                    'phid' => 'PHID-PROJ-1',
                    'fields' => [
                        'name' => 'Employees',
                    ],
                ],
                [
                    'id' => 42,
                    'type' => 'PROJ',
                    'phid' => 'PHID-PROJ-2',
                    'fields' => [
                        'name' => 'Collaborators',
                    ],
                ]
            ],
            'cursor' => [
                'after' => null,
            ]
        ];
        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('project.search', [
            'constraints' => [
                'icons' => [
                    'group',
                ],
            ],
        ])
        ->once()
        ->andReturn($groups);
        $this->lookupGroupProjects()->shouldReturn($groups);
    }

    function it_creates_a_new_phabricator_project_from_redmine_details()
    {
        $detail = [
            'project' => [
                'id' => 59,
                'name' => 'Test Project',
                'description' => 'Project description',
                'status' => 1,
            ]
        ];
        $phab_members = [
            'PHID-USER-dj4xmfuwa6z4ycm6d764',
            'PHID-USER-jfllws6pouiwhzflb3p7',
        ];
        $policies = [
            'view' => 'PHID-PROJ-5lryozud2u4wog3ah7lt',
            'edit' => 'PHID-PROJ-5lryozud2u4wog3ah7lt',
        ];
        $project_edited = [
            'object' => [
                'id' => 64,
                'phid' => 'PHID-PROJ-jtvatlu3ptn6lepfqjr6',
            ],
            'transactions' => [
                [
                    'phid' => 'PHID-XACT-PROJ-fgbfirms6x7e5kn',
                ],
                [
                    'phid' => 'PHID-XACT-PROJ-mmn5wo6efgyxaf6',
                ],
                [
                    'phid' => 'PHID-XACT-PROJ-dafrj6yrkdjojdj',
                ],
                [
                    'phid' => 'PHID-XACT-PROJ-ls7kyu3v77ahi4t',
                ],
                [
                    'phid' => 'PHID-XACT-PROJ-3mpflqb4isvr7gh',
                ],
                [
                    'phid' => 'PHID-XACT-PROJ-wjzzunrkrtaao6s',
                ],
            ]
        ];

        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('project.edit', [
            'objectIdentifier' => null,
            'transactions' => [
                [
                    'type' => 'name',
                    'value' => 'Test Project',
                ],
                [
                    'type' => 'members.add',
                    'value' => [
                        'PHID-USER-dj4xmfuwa6z4ycm6d764',
                        'PHID-USER-jfllws6pouiwhzflb3p7',
                    ]
                ],
                [
                    'type' => 'view',
                    'value' => 'PHID-PROJ-5lryozud2u4wog3ah7lt'
                ],
                [
                    'type' => 'edit',
                    'value' => 'PHID-PROJ-5lryozud2u4wog3ah7lt'
                ],
                [
                    'type' => 'join',
                    'value' => 'PHID-PROJ-5lryozud2u4wog3ah7lt'
                ],
            ],
        ])
        ->andReturn($project_edited);

        $this->createNewPhabricatorProject(
            $detail,
            $phab_members,
            $policies
        )->shouldReturn($project_edited);
    }

    function it_validates_user_input_for_selections()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('3');
        ob_start();
        $this->selectIndexFromList('Select something:', 4)->shouldReturn('3');
        $out = ob_get_clean();
        expect($out)->toBe('Select something:' . PHP_EOL.'> ');
    }

    function it_accepts_empty_input_for_selections_if_requested()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('');
        ob_start();
        $this->selectIndexFromList('Select something:', 4, 0, true)->shouldReturn('');
        $out = ob_get_clean();
        expect($out)->toBe('Select something:' . PHP_EOL.'> ');
    }

    function it_recurses_if_the_select_value_does_not_validate()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('4', '2');
        ob_start();
        $this->selectIndexFromList('Select something:', 2)->shouldReturn('2');
        $out = ob_get_clean();
        expect($out)->toBe('Select something:' . PHP_EOL.'> You must select a value between 0 and 2' . PHP_EOL . 'Select something:' . PHP_EOL . '> ');
    }

    function it_prints_a_message_if_the_given_project_id_does_not_exist()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('3', 'foobar');
        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('project.search', [
            'queryKey' => 'all',
            'after' => 0,
        ])
        ->once()
        ->andReturn([
            'data' => [
                [
                    'id' => 1,
                    'phid' => 'PHID-project-1',
                    'fields' => [
                        'name' => 'First project',
                    ]
                ],
                [
                    'id' => 4,
                    'phid' => 'PHID-project-2',
                    'fields' => [
                        'name' => 'Second project',
                    ]
                ],
            ],
            'cursor' => [
                'after' => null,
            ],
        ]);
        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('project.query', [
            'slugs' => ['foobar']
        ])
        ->once()
        ->andReturn([]);

        ob_start();
        $this->actOnChoice('', 1);
        $print = ob_get_clean();

        expect($print)->toBe(
            "2 total projects retrieved.\n\n[1 – First project]\n[4 – Second project]\n\nPlease select (type) a project ID or leave empty to go back to the previous step:\n> Sorry, if a project with id 3 exists, you don't seem to have access to it. Please check your permissions and the id you specified and try again.\nNow you've got to decide where to put all that stuff... decisions, decisions!\nPlease enter the id or slug of the project in Phabricator if you know it\nor press\n\t[Enter] to see a list of available projects in Phabricator,\n\t[0] to create a new project from the Redmine project's details or\n\t[q] to quit and abort\n> "
        );
    }


    function it_caches_phabricator_user_lookups()
    {
        $lookupone = [
            'James',
            'Alfred',
            'John',
        ];

        $lookuptwo = [
            'James',
            'Alfred',
        ];

        $query_result = [
            [
                'phid' => 'phidone',
                'realName' => 'James',
            ],
            [
                'phid' => 'phidtwo',
                'realName' => 'Alfred',
            ],
            [
                'phid' => 'phidthree',
                'realName' => 'John',
            ],
        ];

        $methodoutcomeone = [
            'phidone',
            'phidtwo',
            'phidthree',
        ];

        $methodoutcometwo = [
            'phidone',
            'phidtwo',
        ];
        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('user.query', ['realnames' => $lookupone])
        ->times(1)
        ->andReturn($query_result);

        $this->getPhabricatorUserPhid($lookupone)->shouldReturn($methodoutcomeone);
        $this->getPhabricatorUserPhid($lookuptwo)->shouldReturn($methodoutcometwo);
    }

    function it_looks_up_assignee_phids_from_phabricator()
    {
        $issue = [
            'assigned_to' => [
                'name' => 'Albert Einstein',
            ]
        ];
        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('user.query', ['realnames' => ['Albert Einstein']])
        ->once()
        ->andReturn([
            [
                'phid' => 'PHID-user-albert',
                'realName' => 'Albert Einstein',
            ]
        ]);
        $this->grabOwnerPhid($issue)->shouldReturn('PHID-user-albert');
    }

    function it_returns_an_empty_array_if_no_existing_user_was_found()
    {
        $lookupone = [
            'James',
            'Alfred',
        ];
        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('user.query', [
            'realnames' => $lookupone
        ])
        ->once()
        ->andReturn([]);

        $this->getPhabricatorUserPhid($lookupone)->shouldReturn([]);
    }

    function it_accepts_indexes_unknown_stati()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('0');
        $issue = [
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Unknown',
            ],
            'description' => 'A random description of a task',
        ];

        ob_start();
        $this->createStatusTransaction($issue)->shouldReturn([
            'type' => 'status',
            'value' => 'open'
        ]);
        $output = ob_get_clean();
        expect($output)->toBe(
            'I was unable to find a matching key for the status "Unknown"!' . PHP_EOL
            . '[0] – open' . PHP_EOL
            . '[1] – resolved' . PHP_EOL
            . 'Please indicate which status to use:' . PHP_EOL
            . '> '
        );
    }

    function it_also_accepts_values_for_unknown_stati()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('open');
        $issue = [
            'subject' => 'Test Subject 2',
            'attachments' => [],
            'status' => [
                'id' => 2,
                'name' => 'Unknown',
            ],
            'description' => 'A random description of another task',
        ];
        ob_start();
        $this->createStatusTransaction($issue)->shouldReturn([
            'type' => 'status',
            'value' => 'open'
        ]);
        $output = ob_get_clean();
        expect($output)->toBe(
            'I was unable to find a matching key for the status "Unknown"!' . PHP_EOL
            . '[0] – open' . PHP_EOL
            . '[1] – resolved' . PHP_EOL
            . 'Please indicate which status to use:' . PHP_EOL
            . '> '
        );
    }

    function it_keeps_asking_if_the_status_cannot_be_used()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('2', 'open');
        $issue = [
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 6,
                'name' => 'Unknown',
            ],
            'description' => 'A random description of a task',
        ];
        ob_start();
        $this->createStatusTransaction($issue)->shouldReturn([
            'type' => 'status',
            'value' => 'open'
        ]);
        $output = ob_get_clean();
        expect($output)->toBe('I was unable to find a matching key for the status "Unknown"!' . PHP_EOL
            . '[0] – open' . PHP_EOL
            . '[1] – resolved' . PHP_EOL
            . 'Please indicate which status to use:' . PHP_EOL
            . '> '
            . 'I was unable to find a matching key for the status "Unknown"!' . PHP_EOL
            . '[0] – open' . PHP_EOL
            . '[1] – resolved' . PHP_EOL
            . 'Please indicate which status to use:' . PHP_EOL
            . '> '
        );
    }

    function it_does_not_ask_twice_for_the_same_unknown_status()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('0');
        $issue = [
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 6,
                'name' => 'Unknown',
            ],
            'description' => 'A random description of a task',
        ];
        ob_start();
        $this->createStatusTransaction($issue)->shouldReturn([
            'type' => 'status',
            'value' => 'open'
        ]);
        $output = ob_get_clean();
        expect($output)->toBe('I was unable to find a matching key for the status "Unknown"!' . PHP_EOL
            . '[0] – open' . PHP_EOL
            . '[1] – resolved' . PHP_EOL
            . 'Please indicate which status to use:' . PHP_EOL
            . '> '
        );
        $this->createStatusTransaction($issue)->shouldReturn([
            'type' => 'status',
            'value' => 'open'
        ]);
    }

    function it_generates_a_title_transaction_for_new_tasks()
    {
        $details = [
            'issue' => [
                'subject' => 'Test Subject',
                'attachments' => [],
                'status' => [
                    'id' => 1,
                    'name' => 'Resolved',
                ],
                'description' => 'A random description of a task',
            ]
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $expectedTransactions = [
            [
                'type' => 'projects.set',
                'value' => ['PHID-random'],
            ],
            [
                'type' => 'title',
                'value' => 'Test Subject',
            ],
            [
                'type' => 'description',
                'value' => 'A random description of a task',
            ],
            [
                'type' => 'status',
                'value' => 'resolved',
            ],
            [
                'type' => 'view',
                'value' => 'PHID-foobar',
            ],
            [
                'type' => 'edit',
                'value' => 'PHID-barbaz',
            ]
        ];

        ob_start();
        $this->assembleTransactionsFor(
            'PHID-random',
            $details['issue'],
            $policies
        )->shouldReturn($expectedTransactions);
        ob_end_clean();
    }

    function it_generates_a_title_transaction_if_the_title_has_changed()
    {
        $details = [
            'issue' => [
                'subject' => 'A changed Subject',
                'attachments' => [],
                'status' => [
                    'id' => 1,
                    'name' => 'Resolved',
                ],
                'description' => 'A random description of a task',
            ]
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $task = [
            'title' => 'Test Subject',
            'description' => '',
        ];

        $expectedTransactions = [
            [
                'type' => 'projects.set',
                'value' => ['PHID-random'],
            ],
            [
                'type' => 'title',
                'value' => 'A changed Subject',
            ],
            [
                'type' => 'description',
                'value' => 'A random description of a task',
            ],
            [
                'type' => 'status',
                'value' => 'resolved',
            ],
            [
                'type' => 'view',
                'value' => 'PHID-foobar',
            ],
            [
                'type' => 'edit',
                'value' => 'PHID-barbaz',
            ]
        ];

        ob_start();
        $this->assembleTransactionsFor(
            'PHID-random',
            $details['issue'],
            $policies,
            $task
        )->shouldReturn($expectedTransactions);
        ob_end_clean();
    }

    function it_does_not_generate_a_transaction_if_the_title_has_not_changed()
    {
        $details = [
            'issue' => [
                'subject' => 'Test Subject',
                'attachments' => [],
                'status' => [
                    'id' => 1,
                    'name' => 'Resolved',
                ],
                'description' => 'A random description of a task',
            ]
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $task = [
            'title' => 'Test Subject',
            'description' => '',
        ];

        $expectedTransactions = [
            [
                'type' => 'projects.set',
                'value' => ['PHID-random'],
            ],
            [
                'type' => 'description',
                'value' => 'A random description of a task',
            ],
            [
                'type' => 'status',
                'value' => 'resolved',
            ],
            [
                'type' => 'view',
                'value' => 'PHID-foobar',
            ],
            [
                'type' => 'edit',
                'value' => 'PHID-barbaz',
            ]
        ];

        ob_start();
        $this->assembleTransactionsFor(
            'PHID-random',
            $details['issue'],
            $policies,
            $task
        )->shouldReturn($expectedTransactions);
        ob_end_clean();
    }


    function it_forces_a_specific_protocol_if_it_has_been_set_in_the_config()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim\Traits', 'file_get_contents')->andReturn('blablabla');
        $details = [
            'issue' => [
                'subject' => 'Force protocol test',
                'attachments' => [
                    [
                        'filename' => 'Testfile.png',
                        'content_url' => 'http://redmine.host/files/Testfile.png',
                    ]
                ],
                'status' => [
                    'id' => 1,
                    'name' => 'Resolved',
                ],
                'description' => 'A random description of a task',
            ]
        ];

        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('file.upload', [
                'name' => 'Testfile.png',
                'data_base64' => base64_encode('blablabla'),
                'viewPolicy' => 'PHID-foobar',
        ])
        ->once()
        ->andReturn('PHID-file-xyz');

        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('file.info', [
                'phid' => 'PHID-file-xyz',
            ])
        ->once()
        ->andReturn([
            'objectName' => 'F123456'
        ]);

        $this->uploadFiles(
            $details['issue'],
            'PHID-foobar'
        )->shouldReturn([
            '{F123456}'
        ]);
    }

    function it_attaches_uploaded_files_to_the_task_description()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim\Traits', 'file_get_contents')->andReturn('blablabla');
        $details = [
            'issue' => [
                'subject' => 'File upload test',
                'attachments' => [
                    [
                        'filename' => 'Testfile.png',
                        'content_url' => 'https://redmine.host/files/Testfile.png',
                    ]
                ],
                'status' => [
                    'id' => 1,
                    'name' => 'Resolved',
                ],
                'description' => 'A random description of a task',
            ]
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('file.upload', [
                'name' => 'Testfile.png',
                'data_base64' => base64_encode('blablabla'),
                'viewPolicy' => $policies['view'],
        ])
        ->once()
        ->andReturn('PHID-file-xyz');

        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('file.info', [
                'phid' => 'PHID-file-xyz',
            ])
        ->once()
        ->andReturn([
            'objectName' => 'F123456'
        ]);

        $expectedTransactions = [
            [
                'type' => 'projects.set',
                'value' => ['PHID-random'],
            ],
            [
                'type' => 'title',
                'value' => 'File upload test',
            ],
            [
                'type' => 'description',
                'value' => "A random description of a task\n\n{F123456}",
            ],
            [
                'type' => 'status',
                'value' => 'resolved',
            ],
            [
                'type' => 'view',
                'value' => 'PHID-foobar',
            ],
            [
                'type' => 'edit',
                'value' => 'PHID-barbaz',
            ]
        ];

        $this->assembleTransactionsFor(
            'PHID-random',
            $details['issue'],
            $policies,
            []
        )->shouldReturn($expectedTransactions);
    }


    function it_transforms_watchers_into_subscribers()
    {
        $issue = [
            'subject' => 'Transform watchers into subscribers',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Resolved',
            ],
            'description' => 'A random description of a task',
            'watchers' => [
                [
                    'id' => 1,
                    'name' => 'Tom Sawyer',
                ],
                [
                    'id' => 5,
                    'name' => 'Miles Davis',
                ]
            ]
        ];

        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $expectedTransactions = [
            [
                'type' => 'projects.set',
                'value' => ['PHID-random'],
            ],
            [
                'type' => 'title',
                'value' => 'Transform watchers into subscribers',
            ],
            [
                'type' => 'description',
                'value' => "A random description of a task",
            ],
            [
                'type' => 'status',
                'value' => 'resolved',
            ],
            [
                'type' => 'subscribers.set',
                'value' => [
                    'PHID-tom',
                    'PHID-miles',
                ],
            ],
            [
                'type' => 'view',
                'value' => 'PHID-foobar',
            ],
            [
                'type' => 'edit',
                'value' => 'PHID-barbaz',
            ],
        ];

        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('user.query', [
            'realnames' => [
                'Tom Sawyer',
                'Miles Davis',
            ],
        ])
        ->once()
        ->andReturn([
            [
                'realName' => 'Tom Sawyer',
                'phid' => 'PHID-tom',
            ],
            [
                'realName' => 'Miles Davis',
                'phid' => 'PHID-miles',
            ]
        ]);

        $this->assembleTransactionsFor(
            'PHID-random',
            $issue,
            $policies,
            []
        )->shouldReturn($expectedTransactions);
    }

    function it_creates_a_new_task_if_no_match_is_found_in_phabricator()
    {
        $issue = [
            'id' => 1,
            'subject' => 'Create a new task',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Resolved',
            ],
            'description' => 'A random description of a task',
        ];
        $policies = [
            'view' => 'PHID-foobar',
            'edit' => 'PHID-barbaz',
        ];

        $result = [
            'title' => 'Create a new task',
            'description' => 'A random description of a task',
            'ownerPHID' => 'PHID-owner',
            'priority' => 100,
            'projectPHIDs' => [
                'PHID-project'
            ],
        ];

        $owner = [
            'realName' => 'Johnny 5',
            'phid' => 'PHID-owner',
        ];

        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('maniphest.query', [
            'projectPHIDs' => ['PHID-project'],
            'fullText' => 'A random description of a task'
        ])
        ->once()
        ->andReturn([]);

        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with(
            'maniphest.edit',
            \Mockery::type('array')
        )
        ->once()
        ->andReturn($result);

        ob_start();
        $this->createManiphestTask(
            $issue,
            $owner,
            'PHID-project',
            $policies
        )->shouldReturn($result);
        ob_end_clean();
    }

    function it_strips_non_word_characters_from_an_issue_description_for_fullText_search()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('0');
        $issue = [
            'id' => 1,
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Resolved',
            ],
            'description' => 'A random description of a task that includes +- 2 special chars that have meaning in mySQL fulltext search indices.',
        ];
        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('maniphest.query', [
            'projectPHIDs' => ['PHID-project'],
            'fullText' => 'A random description of a task that includes 2 special chars that have meaning in mySQL fulltext search indices '
        ])
        ->once()
        ->andReturn([
            [
                'id' => 1,
                'statusName' => 'Resolved',
                'title' => 'Test Subject',
                'description' => 'A random description of a task that includes +- 2 special chars that have meaning in mySQL fulltext search indices.',
            ]
        ]);
        // ob_start();
        $this->findExistingTask($issue, 'PHID-project')->shouldReturn([
            'id' => 1,
            'statusName' => 'Resolved',
            'title' => 'Test Subject',
            'description' => 'A random description of a task that includes +- 2 special chars that have meaning in mySQL fulltext search indices.',
        ]);
        // ob_end_clean();
    }

    function it_asks_which_task_to_update_if_more_than_one_existing_task_is_found()
    {
        $mock = PHPMockery::mock('\Ttf\Remaim', 'fgets')->andReturn('0');
        $issue = [
            'id' => 1,
            'subject' => 'Test Subject',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Resolved',
            ],
            'description' => 'A random description of a task',
        ];
        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('maniphest.query', [
            'projectPHIDs' => ['PHID-project'],
            'fullText' => 'A random description of a task'
        ])
        ->once()
        ->andReturn([
            [
                'id' => 1,
                'statusName' => 'Resolved',
                'title' => 'Test Subject',
                'description' => 'A random description of a task',
            ],
            [
                'id' => 2,
                'statusName' => 'Open',
                'title' => 'Similar Task Subject',
                'description' => 'A random description of a task',
            ],
        ]);

        ob_start();
        $this->findExistingTask($issue, 'PHID-project')->shouldReturn([
            'id' => 1,
            'statusName' => 'Resolved',
            'title' => 'Test Subject',
            'description' => 'A random description of a task',
        ]);
        $prompt = ob_get_clean();
        expect($prompt)->toBe(
            "Oops, looks like I found more than one existing task in Phabricator that matches the following one:\n\n[#1] \"Test Subject\"\nDescription (shortnd.): \n\nPlease indicate which one to update or press Enter to create a new task.\n[0] =>\t[ID]: T1\n\t[Status]: Resolved\n\t[Name]: Test Subject\n\t[Description]: A random description of a task\n[1] =>\t[ID]: T2\n\t[Status]: Open\n\t[Name]: Similar Task Subject\n\t[Description]: A random description of a task\n[2] =>\tSKIP this issue.\n\tSelect this entry to entirely skip this issue, not updating any of the above Maniphest tasks.\n\tNote: If you run remaim with the -r flag, this behavior will be the default when I encounter existing tasks.\n\nZOMG, what shall I do?\n> "
        );
    }

    function it_updates_the_task_if_only_one_is_found_in_phabricator()
    {
        $issue = [
            'subject' => 'Only one is found',
            'attachments' => [],
            'status' => [
                'id' => 1,
                'name' => 'Resolved',
            ],
            'description' => 'A random description of a task',
        ];
        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with('maniphest.query', [
            'projectPHIDs' => ['PHID-project'],
            'fullText' => 'A random description of a task'
        ])
        ->once()
        ->andReturn([
            [
                'id' => 1,
                'statusName' => 'Resolved',
                'title' => 'Only one is found',
                'description' => 'A random description of a task',
            ]
        ]);
        $this->findExistingTask($issue, 'PHID-project')->shouldReturn([
            'id' => 1,
            'statusName' => 'Resolved',
            'title' => 'Only one is found',
            'description' => 'A random description of a task',
        ]);
    }

    function it_paginates_trough_all_the_results_if_there_are_more_than_100()
    {
        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with(
            'project.search',
            [
                'queryKey' => 'all',
                'after' => 0,
            ]
        )
        ->once()
        ->andReturn([
            'data' => ['a', 'b'],
            'cursor' => [
                'after' => 23
            ]
        ]);

        $this->container['conduit']
        ->shouldReceive('callMethodSynchronous')
        ->with(
            'project.search',
            [
                'queryKey' => 'all',
                'after' => 23,
            ]
        )
        ->once()
        ->andReturn([
            'data' => ['c', 'd'],
            'cursor' => [
                'after' => null
            ]
        ]);

        $this->retrieveAllPhabricatorProjects(0)->shouldReturn([
            'a', 'b', 'c', 'd'
        ]);
    }
}
