<?php
declare(strict_types=1);
namespace Tesco\Tests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tesco\{ApiError, TescoClient};

final class TescoClientTest extends TestCase
{
    private function transport(array $entry): FakeTransport
    {
        return new FakeTransport(['status' => 200, 'body' => json_encode([$entry], JSON_THROW_ON_ERROR)]);
    }

    public function testSearchKeepsUserInputInVariablesAndNormalizesMoney(): void
    {
        $transport = $this->transport(['data' => ['search' => ['info' => ['total' => 1], 'results' => [['node' => Fixtures::node()]]]]]);
        $query = 'milk & "bread" { }';
        $result = (new TescoClient($transport))->search($query, 2, 3);
        self::assertSame($query, $transport->operations[0]['variables']['query']);
        self::assertStringNotContainsString($query, $transport->operations[0]['query']);
        self::assertSame(2, $transport->operations[0]['variables']['page']);
        self::assertSame(340, $result['products'][0]['price_cents']);
        self::assertSame(25, $result['products'][0]['deposit_cents']);
        self::assertSame('EUR', $result['products'][0]['currency']);
    }

    public function testProductLookupUsesTpncAndRejectsWrongProduct(): void
    {
        $transport = $this->transport(['data' => ['product' => Fixtures::node()]]);
        $client = new TescoClient($transport);
        self::assertSame('315853194', $client->product('315853194')['id']);
        self::assertSame(['tpnc' => '315853194'], $transport->operations[0]['variables']);
        $this->expectException(ApiError::class);
        $client->product('292769732');
    }

    #[DataProvider('failures')]
    public function testUpstreamFailuresAreNotEmptySuccesses(int $httpStatus, string $body, string $code, int $status): void
    {
        $transport = new FakeTransport(['status' => $httpStatus, 'body' => $body]);
        try {
            (new TescoClient($transport))->product('315853194');
            self::fail('Expected an upstream error');
        } catch (ApiError $e) {
            self::assertSame($code, $e->errorCode);
            self::assertSame($status, $e->status);
            self::assertStringNotContainsString('secret-detail', $e->getMessage());
        }
    }

    public static function failures(): iterable
    {
        yield 'http blocked' => [403, '<html>blocked</html>', 'upstream_access_denied', 502];
        yield 'http throttled' => [429, '', 'upstream_rate_limited', 503];
        yield 'http failure' => [500, '', 'upstream_unavailable', 502];
        yield 'invalid JSON' => [200, '<html>challenge</html>', 'upstream_invalid_json', 502];
        yield 'bad envelope' => [200, '{"data":{}}', 'upstream_schema', 502];
        yield 'GraphQL partial data' => [200, '[{"data":{"product":{}},"errors":[{"message":"secret-detail"}]}]', 'upstream_graphql', 502];
        yield 'GraphQL throttled' => [200, '[{"errors":[{"extensions":{"http":{"status":429}}}],"status":429}]', 'upstream_rate_limited', 503];
        yield 'missing product' => [200, '[{"errors":[{"message":"product-not-found"}],"status":404}]', 'product_not_found', 404];
        yield 'null product' => [200, '[{"data":{"product":null}}]', 'product_not_found', 404];
        yield 'missing data' => [200, '[{"status":200}]', 'upstream_schema', 502];
        yield 'scalar product' => [200, '[{"data":{"product":"bad"}}]', 'upstream_schema', 502];
        yield 'scalar envelope' => [200, '"bad"', 'upstream_schema', 502];
        yield 'scalar errors' => [200, '[{"errors":"bad"}]', 'upstream_schema', 502];
        yield 'scalar error entry' => [200, '[{"errors":["bad"]}]', 'upstream_schema', 502];
        yield 'status only throttle' => [200, '[{"status":429}]', 'upstream_rate_limited', 503];
    }

    public function testEmptySearchIsValidButMalformedProductIsNot(): void
    {
        $transport = $this->transport(['data' => ['search' => ['info' => ['total' => 0], 'results' => []]]]);
        self::assertSame([], (new TescoClient($transport))->search('no matches')['products']);
        $transport->response['body'] = '[{"data":{"search":{"info":{"total":1},"results":[{"node":{}}]}}}]';
        $this->expectException(ApiError::class);
        (new TescoClient($transport))->search('milk');
    }

    public function testInvalidInputNeverCallsUpstream(): void
    {
        $transport = $this->transport([]);
        try {
            (new TescoClient($transport))->search('', 1, 100);
            self::fail('Expected validation error');
        } catch (ApiError $e) {
            self::assertSame(422, $e->status);
            self::assertSame([], $transport->operations);
        }
    }

    public function testInvalidUtf8IsRejectedBeforeJsonEncoding(): void
    {
        $transport = $this->transport([]);
        try {
            (new TescoClient($transport))->search("\xFF");
            self::fail('Expected validation error');
        } catch (ApiError $e) {
            self::assertSame(422, $e->status);
            self::assertSame([], $transport->operations);
        }
    }
}
