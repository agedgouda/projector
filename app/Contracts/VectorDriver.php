<?php

namespace App\Contracts;

interface VectorDriver
{
    public function getEmbedding(string $text): array;
}
