<?php

declare(strict_types=1);

namespace Groddle\Minn;

/** An op the level builder cannot apply (BuilderOps): its code, its words, and which op of a batch. */
final class BuilderOpError extends \RuntimeException
{
    public ?int $index = null;

    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function at(int $index): self
    {
        $this->index = $index;
        return $this;
    }
}
