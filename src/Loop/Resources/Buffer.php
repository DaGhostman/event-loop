<?php
declare(strict_types=1);

namespace Onion\Framework\Loop\Resources;

class Buffer
{
    private string $contents = '';
    private int $size = 0;
    private int $cursor = 0;

    public function __construct(
        private readonly int $limit = -1
    ) {
    }

    public function read(int $length): ?string
    {
        $current = substr($this->contents, $this->cursor, $length);
        $this->cursor += strlen($current);

        return $current ?: null;
    }

    public function write(string $data): int
    {
        $length = strlen($data);
        if ($this->limit !== -1 && $this->limit < ($this->size + $length)) {
            throw new \OverflowException('Buffer limit reached');
        }

        $this->contents .= $data;
        $this->size += $length;

        return $length;
    }

    // public function seek(int $position, int $whence = SEEK_SET): void
    // {
    //     $this->cursor = match ($whence) {
    //         SEEK_SET => $position,
    //         SEEK_CUR => $this->cursor + $position,
    //         SEEK_END => $this->size,
    //     };
    // }

    // public function tell(): int
    // {
    //     return $this->cursor;
    // }

    // public function rewind(): void
    // {
    //     $this->cursor = 0;
    // }

    // public function flush(): void
    // {
    //     $this->contents = '';
    //     $this->size = 0;
    //     $this->cursor = 0;
    // }

    // public function drain(int $cursor = null): void
    // {
    //     $this->contents = substr($this->contents, $cursor ?? $this->cursor);
    //     $this->size = strlen($this->contents);
    //     $this->cursor = 0;
    // }

    public function eof(): bool
    {
        return $this->cursor === $this->size;
    }

	/**
	 * Close the underlying resource
	 * @return bool Whether the operation succeeded or not
	 */
	public function close(): bool {
        $this->limit = 0;
        // todo: prevent further operations
	}

	/**
	 * Attempt to make operations on the underlying resource blocking
	 * @return bool Whether the operation succeeded or not
	 */
	public function block(): bool {
        return true;
	}

	/**
	 * Attempt to make operations on the underlying resource non-blocking
	 * @return bool Whether the operation succeeded or not
	 */
	public function unblock(): bool {
        return true;
	}

	/**
	 * Attempt to acquire or release an already acquired lock on the
	 * underlying resource
	 *
	 * @param int $lockType Same as flock's $operation parameter
	 * @return bool Whether a lock has been acquired/released or not
	 */
	public function lock(int $lockType): bool {
        return true;
	}

	/**
	 * Attempt to release an already acquired lock on the underlying
	 * resource
	 * @return bool Whether a lock has been released or not
	 */
	public function unlock(): bool {
        return true;
	}

	/**
	 * Suspend the execution of the coroutine from which this method is
	 * called until the provided operation is possible on the current
	 * resource
	 *
	 * @param \Onion\Framework\Loop\Types\Operation $operation The operation to wait for
	 * @return void
	 */
	public function wait(\Onion\Framework\Loop\Types\Operation $operation = Onion\Framework\Loop\Types\Operation::READ): void {
        // nothing to do
	}

	/**
	 * Returns the underlying resource
	 * @return resource
	 */
	public function getResource() {
        // todo: handle creating of temp resource
	}

	/**
	 * Retrieve the numeric identifier of the underlying resource
	 * @return int
	 */
	public function getResourceId(): int {
        // todo: implement based on getResource()
	}

	/**
	 * @return bool
	 */
	public function isAlive(): bool {

	}

	/**
	 * Detaches the underlying resource from the current object and
	 * returns it, making the current object obsolete
	 * @return resource
	 */
	public function detach(): mixed {
        // todo invalidate resource & prevent further operations
	}
}
