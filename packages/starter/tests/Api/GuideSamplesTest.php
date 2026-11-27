<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Models\Job;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Preflow\Core\Exceptions\NotFoundHttpException;
use Preflow\Data\DataManager;
use Preflow\Data\Describe\Description;
use Tests\Fixtures\Guides\GraphqlSdl;
use Tests\Fixtures\Guides\V1JobsController;
use Tests\Support\StarterApp;

/** The code the preflow.dev API guides quote (tests/Fixtures/Guides), run against this starter. */
final class GuideSamplesTest extends TestCase
{
    use StarterApp;

    protected function setUp(): void
    {
        $this->bootStarter();
    }

    protected function tearDown(): void
    {
        $this->removeStarter();
    }

    private function jobWith(string $status): Job
    {
        $job = $this->container()->get(DataManager::class)->query(Job::class)->where('status', $status)->first();
        $this->assertInstanceOf(Job::class, $job);

        return $job;
    }

    /** @return array<string, mixed> the answer's "data" */
    private function v1Show(string $id): array
    {
        $request = (new Psr17Factory())->createServerRequest('GET', '/v1/jobs/' . $id)->withAttribute('id', $id);
        $response = $this->container()->get(V1JobsController::class)->show($request);

        return json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR)['data'];
    }

    public function test_v1_links_follow_relations(): void
    {
        $job = $this->jobWith('published');

        $answer = $this->v1Show((string) $job->uuid);

        $this->assertSame($job->uuid, $answer['_id']);
        $this->assertSame(['self', 'company'], array_keys($answer['_links']), 'self, then one link per relation field');
        $this->assertSame(['href' => '/v1/jobs/' . $job->uuid], $answer['_links']['self']);
        // job.company is a relation: the description names its target, "company", which lives at /v1/companies.
        $this->assertSame(['href' => '/v1/companies/' . $job->company], $answer['_links']['company']);

        $this->expectException(NotFoundHttpException::class);
        $this->v1Show((string) $this->jobWith('draft')->uuid);
    }

    public function test_graphql_sdl_from_the_description(): void
    {
        $description = $this->container()->get(Description::class)->toArray();

        $this->assertSame(<<<'SDL'
            type Job {
              _id: ID!
              title: String!
              slug: String
              company: Company!
              place: String!
              employmentType: String!
              remote: String!
              salaryFrom: Float
              salaryTo: Float
              description: String!
              status: String
              publishedAt: String
              createdAt: String
              updatedAt: String
            }

            type Company {
              _id: ID!
              name: String!
              slug: String
              place: String
              website: String
              summary: String
            }

            type Query {
              job(id: ID!, locale: String): Job
              jobList(page: Int = 1, locale: String): [Job!]!
              company(id: ID!, locale: String): Company
              companyList(page: Int = 1, locale: String): [Company!]!
            }

            SDL, GraphqlSdl::build($description, ['job', 'company']));
    }
}
