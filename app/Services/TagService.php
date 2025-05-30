<?php

namespace App\Services;

use App\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class TagService
{
    public function normalizeTagName(string $name): string
    {
        return strtolower(trim($name));
    }

    public function createOrFindTags(array $tagNames): Collection
    {
        $normalizedTags = array_map([$this, 'normalizeTagName'], $tagNames);
        $uniqueTags = array_unique($normalizedTags);

        return collect($uniqueTags)->map(function($tagName) {
            return Tag::firstOrCreate([
                'name' => $tagName,
                'slug' => Str::slug($tagName)
            ]);
        });
    }

    public function searchByTags(array $tagNames): Collection
    {
        $normalizedTags = array_map([$this, 'normalizeTagName'], $tagNames);
        $uniqueTags = array_unique($normalizedTags);

        return Tag::whereIn('name', $uniqueTags)->get();
    }
} 