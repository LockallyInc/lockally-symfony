<?php

declare(strict_types=1);

namespace Lockally\Symfony\Tests;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Lockally\SDK\Api\SendApi;
use Lockally\SDK\Configuration;
use Lockally\Symfony\LockallyTransport;
use Lockally\Symfony\LockallyTransportFactory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

final class LockallyTransportTest extends TestCase
{
    /** @param array<int, array{request: RequestInterface}> $history */
    private function transport(array &$history): LockallyTransport
    {
        $mock = new MockHandler([
            new Response(202, ['Content-Type' => 'application/json'], (string) json_encode([
                'id' => 'm_1', 'message_id' => '<x@lockally.com>', 'status' => 'queued',
            ])),
        ]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $cfg = Configuration::getDefaultConfiguration()
            ->setAccessToken('lk_test_abc')
            ->setHost('https://api.lockally.com');

        return new LockallyTransport(new SendApi(new Client(['handler' => $stack]), $cfg));
    }

    public function testMapsEmailToSendRequest(): void
    {
        $history = [];
        $email = (new Email())
            ->from('alerts@acme.com')
            ->to('user@example.com')
            ->cc('cc@example.com')
            ->replyTo('reply@acme.com')
            ->subject('Your code')
            ->text('code 123')
            ->html('<b>code 123</b>');
        $email->addPart(new DataPart('BYTES', 'invoice.pdf', 'application/pdf'));

        $req = $this->transport($history)->toRequest($email);

        $this->assertSame('alerts@acme.com', $req->getFrom());
        $this->assertSame(['user@example.com'], $req->getTo());
        $this->assertSame(['cc@example.com'], $req->getCc());
        $this->assertSame('Your code', $req->getSubject());
        $this->assertSame('code 123', $req->getText());
        $this->assertSame('<b>code 123</b>', $req->getHtml());

        $headers = $req->getHeaders();
        $this->assertArrayHasKey('Reply-To', $headers);
        $this->assertStringContainsString('reply@acme.com', $headers['Reply-To']);

        $attachments = $req->getAttachments();
        $this->assertCount(1, $attachments);
        $this->assertSame('invoice.pdf', $attachments[0]->getFilename());
        $this->assertSame('application/pdf', $attachments[0]->getContentType());
        $this->assertSame(base64_encode('BYTES'), $attachments[0]->getContentBase64());
    }

    public function testSendPostsToV1SendWithIdempotencyKeyAndBearer(): void
    {
        $history = [];
        $transport = $this->transport($history);

        $email = (new Email())->from('a@acme.com')->to('b@example.com')->subject('hi')->text('yo');
        $transport->send($email);

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('POST', $request->getMethod());
        $this->assertStringEndsWith('/v1/send', (string) $request->getUri());
        $this->assertNotEmpty($request->getHeaderLine('Idempotency-Key'));
        $this->assertStringContainsString('Bearer lk_test_abc', $request->getHeaderLine('Authorization'));

        $body = json_decode((string) $request->getBody(), true);
        $this->assertSame('a@acme.com', $body['from']);
        $this->assertSame(['b@example.com'], $body['to']);
        $this->assertSame('yo', $body['text']);
    }

    public function testFactoryBuildsTransportForDsnScheme(): void
    {
        $factory = new LockallyTransportFactory();
        $dsn = new Dsn('lockally+api', 'default', 'lk_test_abc');

        $this->assertTrue($factory->supports($dsn));
        $this->assertInstanceOf(LockallyTransport::class, $factory->create($dsn));
    }
}
