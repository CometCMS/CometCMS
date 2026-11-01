<?php

declare(strict_types=1);

namespace CometCMS\Controllers\Admin;

use CometCMS\Content\ContentRepository;
use CometCMS\Content\ContentTypeRepository;
use CometCMS\Core\Http;
use CometCMS\Core\Security;
use CometCMS\Core\ValidationException;
use CometCMS\Fields\FieldRegistry;

final class ContentTypesController extends BaseController
{
    private ContentTypeRepository $types;
    private FieldRegistry $fields;
    private ContentRepository $content;

    public function __construct(Http $http)
    {
        parent::__construct($http);
        $this->types = new ContentTypeRepository();
        $this->fields = FieldRegistry::builtins();
        $this->content = ContentRepository::make();
    }

    public function index(): never
    {
        $this->requirePermission('schema.read', ['type' => 'schema']);
        $this->json(['data' => $this->types->all(), 'meta' => ['field_types' => $this->fields->names()]]);
    }

    public function show(string $name): never
    {
        if (!$this->types->exists($name)) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Content type not found.']], 404);
        }

        $user = $this->requireUser();
        $canReadSchema = $this->permissions->allows($user, 'schema.read', [
            'type' => 'schema',
            'name' => $name,
            'principal' => $user,
        ]);
        $canReadContent = $this->permissions->allows($user, 'content.read', [
            'type' => 'content',
            'collection' => $name,
            'principal' => $user,
        ]);

        if (!$canReadSchema && !$canReadContent) {
            $this->json(['error' => ['code' => 'forbidden', 'message' => 'You do not have permission to perform this action.']], 403);
        }

        $this->json(['data' => $this->types->find($name)]);
    }

    public function store(): never
    {
        $body = $this->requestJson();
        $name = $this->schemaName($body['name'] ?? '');
        $user = $this->requirePermission('schema.create', ['type' => 'schema', 'name' => $name]);
        $this->verifyCsrf();

        if ($name === '') {
            $this->json(['error' => [
                'code' => 'validation_failed',
                'message' => 'Choose an identifier for this content type.',
                'fields' => ['name' => ['Choose an identifier for this content type.']],
            ]], 422);
        }

        if ($this->types->exists($name, includeDisabled: true)) {
            $this->json(['error' => [
                'code' => 'validation_failed',
                'message' => 'A content type with this identifier already exists.',
                'fields' => ['name' => ['A content type with this identifier already exists.']],
            ]], 422);
        }

        $body['name'] = $name;

        try {
            $this->types->save($body);
        } catch (ValidationException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->fields()]], 422);
        }
        $this->cache->clear();
        $this->logger->info('content_type.created', ['name' => $name, 'user_id' => $user['id'] ?? null]);
        $this->json(['data' => $this->types->find($name)], 201);
    }

    private function schemaName(mixed $value): string
    {
        $name = strtolower(trim((string) $value));
        $name = preg_replace('/[^a-z0-9_-]+/', '-', $name) ?? '';

        return trim($name, '-_');
    }

    public function reorder(): never
    {
        $this->requirePermission('schema.update', ['type' => 'schema']);
        $this->verifyCsrf();
        $body = $this->requestJson();
        $names = $body['names'] ?? [];

        if (!is_array($names)) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => 'names must be an array.']], 422);
        }

        $this->cache->clear();
        $this->json(['data' => $this->types->reorder($names), 'meta' => ['field_types' => $this->fields->names()]]);
    }

    public function update(string $name): never
    {
        $user = $this->requirePermission('schema.update', ['type' => 'schema', 'name' => $name]);
        $body = $this->requestJson();
        $this->verifyCsrf();
        $migrations = is_array($body['migrations'] ?? null) ? $body['migrations'] : [];
        unset($body['migrations']);
        $body['name'] = $name;

        try {
            $this->types->save($body);
        } catch (ValidationException $e) {
            $this->json(['error' => ['code' => 'validation_failed', 'message' => $e->getMessage(), 'fields' => $e->fields()]], 422);
        }
        $schema = $this->types->find($name);

        if ($migrations !== []) {
            $this->content->migrateFields($name, $migrations);
        }

        $this->cache->clear();
        $this->logger->info('content_type.updated', ['name' => $name, 'user_id' => $user['id'] ?? null]);
        $this->json(['data' => $schema]);
    }

    public function destroy(string $name): never
    {
        if ($this->types->isDisabled($name)) {
            $this->json(['error' => ['code' => 'not_found', 'message' => 'Content type not found.']], 404);
        }
        $user = $this->requirePermission('schema.delete', ['type' => 'schema', 'name' => $name]);
        $this->verifyCsrf();
        $this->types->delete($name);
        $this->cache->clear();
        $this->logger->info('content_type.deleted', ['name' => $name, 'user_id' => $user['id'] ?? null]);
        $this->json(['data' => ['ok' => true]]);
    }
}
