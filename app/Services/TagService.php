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
            $slug = Str::slug($tagName);
            
            // First try to find by slug (since that's the unique constraint)
            $existingTag = Tag::where('slug', $slug)->first();
            if ($existingTag) {
                return $existingTag;
            }
            
            // If not found by slug, create new one
            return Tag::create([
                'name' => $tagName,
                'slug' => $slug
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