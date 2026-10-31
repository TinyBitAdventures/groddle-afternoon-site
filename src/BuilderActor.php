<?php

declare(strict_types=1);

namespace Groddle\Minn;

/**
 * Who a level builder request speaks for (BuilderAuth): an administrator by the session, or a
 * builder key. $admin is the user the log line names (the key's creator for a key); $author is
 * what the drafts and revisions record as the writer: the user id, or "key:<id>".
 */
final readonly class BuilderActor
{
    public function __construct(public int $admin, public string $author, public ?string $keyId = null, public string $keyLabel = '')
    {
    }

    public static function session(int $admin): self
    {
        return new self($admin, (string) $admin);
    }

    /** @param array{id: string, label: string, createdBy: int} $key */
    public static function key(array $key): self
    {
        return new self($key['createdBy'], 'key:' . $key['id'], $key['id'], $key['label']);
    }

    public function isKey(): bool
    {
        return $this->keyId !== null;
    }
}
