<?php

declare(strict_types=1);

namespace App\Support;

/**
 * A fully resolved update target: a branch or a tag and the ref it names.
 *
 * Resolving once keeps the preview, the pipeline, and the rollback aligned: a
 * tag is checked out detached by commit, never treated as a remote branch.
 */
final readonly class GitTarget
{
    /** A remote-tracking branch (`refs/remotes/origin/<name>`). */
    public const string KIND_BRANCH = 'branch';

    /** An annotated or lightweight tag (`refs/tags/<name>`). */
    public const string KIND_TAG = 'tag';

    /**
     * Create a resolved target.
     */
    public function __construct(
        public string $name,
        public string $kind,
        public string $ref,
    ) {}

    /**
     * Whether this target is a branch.
     */
    public function isBranch(): bool
    {
        return $this->kind === self::KIND_BRANCH;
    }

    /**
     * Whether this target is a tag.
     */
    public function isTag(): bool
    {
        return $this->kind === self::KIND_TAG;
    }
}
