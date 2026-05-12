<?php

namespace Anwar\GunmaAgent\Http\Controllers;

use Anwar\GunmaAgent\Services\PromptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PromptController extends Controller
{
    public function __construct(
        private readonly PromptService $promptService
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->promptService->getAll(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'system_prompt' => 'nullable|string',
            'response_style' => 'nullable|in:short,balanced,detailed',
        ]);

        foreach ($validated as $key => $value) {
            if ($value !== null) {
                $this->promptService->setPrompt($key, $value);
            }
        }

        $this->promptService->clearCache();

        return response()->json([
            'message' => 'Prompts updated successfully',
            'data' => $this->promptService->getAll(),
        ]);
    }

    public function show(string $key): JsonResponse
    {
        $all = $this->promptService->getAll();

        if (!isset($all[$key])) {
            return response()->json(['message' => 'Prompt not found'], 404);
        }

        return response()->json(['data' => [$key => $all[$key]]]);
    }
}
