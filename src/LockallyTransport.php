<?php

declare(strict_types=1);

namespace Lockally\Symfony;

use Lockally\SDK\Api\SendApi;
use Lockally\SDK\Model\V1SendPostRequest;
use Lockally\SDK\Model\V1SendPostRequestAttachmentsInner;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

/**
 * Symfony Mailer transport that delivers through the Lockally API (POST /v1/send).
 *
 * Wire it up with a DSN — `MAILER_DSN=lockally+api://YOUR_API_KEY@default` — and
 * every `$mailer->send($email)` (including Messenger-queued mail) routes through
 * Lockally. The Email → /v1/send mapping is the same one the Laravel driver uses.
 */
final class LockallyTransport extends AbstractTransport
{
    public function __construct(private readonly SendApi $api)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        // One idempotency key per logical send (the API requires the header and
        // dedupes on it for 24h — a retry of the same send must reuse the key).
        $this->api->v1SendPost($this->idempotencyKey(), $this->toRequest($email));
    }

    /**
     * Map a Symfony Email onto the Lockally send request. Public so the mapping
     * is unit-testable without performing a real send.
     */
    public function toRequest(Email $email): V1SendPostRequest
    {
        $data = [
            'from' => $this->firstAddress($email->getFrom()),
            'to' => $this->addresses($email->getTo()),
            'subject' => $email->getSubject(),
        ];
        if ($cc = $this->addresses($email->getCc())) {
            $data['cc'] = $cc;
        }
        if ($bcc = $this->addresses($email->getBcc())) {
            $data['bcc'] = $bcc;
        }
        if (($text = $email->getTextBody()) !== null) {
            $data['text'] = (string) $text;
        }
        if (($html = $email->getHtmlBody()) !== null) {
            $data['html'] = (string) $html;
        }

        $headers = $this->headers($email);
        if ($headers) {
            $data['headers'] = $headers;
        }

        $attachments = [];
        foreach ($email->getAttachments() as $part) {
            $attachments[] = new V1SendPostRequestAttachmentsInner([
                'filename' => $part->getFilename() ?? 'attachment',
                'content_type' => $part->getMediaType() . '/' . $part->getMediaSubtype(),
                'content_base64' => base64_encode($part->getBody()),
            ]);
        }
        if ($attachments) {
            $data['attachments'] = $attachments;
        }

        return new V1SendPostRequest($data);
    }

    /**
     * Custom headers to forward. Lockally has no `reply_to` field, so Reply-To is
     * carried in the headers map; standard MIME headers Symfony manages are skipped.
     *
     * @return array<string, string>
     */
    private function headers(Email $email): array
    {
        $out = [];
        if ($replyTo = $email->getReplyTo()) {
            $out['Reply-To'] = implode(', ', array_map(static fn (Address $a) => $a->toString(), $replyTo));
        }
        $managed = [
            'from', 'to', 'cc', 'bcc', 'subject', 'reply-to', 'sender',
            'content-type', 'content-transfer-encoding', 'mime-version', 'date', 'message-id',
        ];
        foreach ($email->getHeaders()->all() as $header) {
            if (in_array(strtolower($header->getName()), $managed, true)) {
                continue;
            }
            $out[$header->getName()] = $header->getBodyAsString();
        }

        return $out;
    }

    /** @param Address[] $addresses @return string[] */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(static fn (Address $a) => $a->getAddress(), $addresses));
    }

    /** @param Address[] $addresses */
    private function firstAddress(array $addresses): ?string
    {
        return $addresses ? $addresses[0]->getAddress() : null;
    }

    private function idempotencyKey(): string
    {
        return 'lk-' . bin2hex(random_bytes(16));
    }

    public function __toString(): string
    {
        return 'lockally+api://api.lockally.com';
    }
}
