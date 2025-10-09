<?php

namespace Comgate\Entity;

/**
 * Represents payment data sent to Comgate.
 */
class Payment {
    /** @var array<string, mixed> */
    private $data;

    /**
     * @param array<string, mixed> $data Key/value payload sent to the API.
     */
    public function __construct(array $data) {
        $this->data = $data;
    }

    /**
     * Returns the data payload ready for transmission.
     *
     * @return array<string, mixed>
     */
    public function toArray() {
        return $this->data;
    }
}
