<?php

declare(strict_types=1);

namespace Cbox\Sync\Laravel\Api;

use Cbox\Sync\Contracts\Store;
use Cbox\Sync\Data\AdapterContext;
use Cbox\Sync\Engine;
use Cbox\Sync\Laravel\Api\Contracts\SyncableType;
use Cbox\Sync\Laravel\Api\Contracts\SyncableTypes;
use Cbox\Sync\Laravel\Api\Contracts\SyncEndpoints;
use Cbox\Sync\Laravel\Api\Exceptions\SyncRequestRejected;
use Cbox\Sync\Laravel\Api\Exceptions\UnknownSyncableType;
use Cbox\Sync\Laravel\Api\Support\FieldValueCodec;
use Cbox\Sync\Laravel\Api\Support\MutationMapper;
use Cbox\Sync\Laravel\Api\Support\Payload;
use Cbox\Sync\Laravel\Api\Support\ResultMapper;
use Cbox\Sync\Laravel\Api\Support\ViewMapper;
use Cbox\Sync\Laravel\Api\ValueObjects\SyncPrincipal;
use Cbox\Sync\Views\BootstrapToken;
use Cbox\Sync\Views\ViewSyncService;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;

class SyncService implements SyncEndpoints
{
    public function __construct(
        private readonly Engine $engine,
        private readonly Store $store,
        private readonly ViewSyncService $views,
        private readonly SyncableTypes $types,
        private readonly Repository $config,
    ) {}

    public function push(Request $request, SyncPrincipal $principal): array
    {
        $body = FieldValueCodec::decodeBody($request->getContent());
        $type = $this->type($body);
        $space = $type->space($principal, Payload::optionalString($body, 'scope'));

        $mutation = MutationMapper::fromWire(
            $body, $principal, $type->entityType(), $space,
            $type->writableFields($principal),
            $this->setting('max_operations', 64),
        );

        // Before the engine, always. A mutation id that reaches it is
        // acknowledged forever, so a refusal afterwards would leave the client
        // unable to retry that id ever again.
        if (! $type->mayWrite($principal, $this->store->record($mutation->entity), $mutation->kind)) {
            throw SyncRequestRejected::forbidden();
        }

        $result = $this->engine->process($mutation, new AdapterContext($principal->id, $principal->integrationId));

        $groups = [];
        foreach ($result->conflictGroupIds as $id) {
            $group = $this->store->group($id);
            if ($group !== null) {
                $groups[$id] = $group;
            }
        }

        return ResultMapper::toWire($result, $type->readableFields($principal), $groups);
    }

    public function bootstrap(Request $request, SyncPrincipal $principal): array
    {
        $body = FieldValueCodec::decodeBody($request->getContent());
        $scope = Payload::optionalString($body, 'scope');
        $type = $this->readableType($body, $principal, $scope);

        $view = $type->view($principal, $scope);
        $context = $this->views->context($type->space($principal, $scope), $view);

        $token = Payload::optionalString($body, 'token');
        $page = $this->views->bootstrap($context, $view, $token !== null
            ? new BootstrapToken($token)
            : $this->views->openBootstrap($context, $view, $this->pageSize($body)));

        return ViewMapper::bootstrapToWire($page, $type->readableFields($principal));
    }

    public function delta(Request $request, SyncPrincipal $principal): array
    {
        $body = FieldValueCodec::decodeBody($request->getContent());
        $scope = Payload::optionalString($body, 'scope');
        $type = $this->readableType($body, $principal, $scope);

        $view = $type->view($principal, $scope);
        $context = $this->views->context($type->space($principal, $scope), $view);
        $cursor = ViewMapper::cursorFromWire($body, $context);

        $budget = min(Payload::optionalInt($body, 'limit') ?? 100, $this->setting('max_commits', 500));
        $page = $this->views->delta($cursor, $view, max(1, $budget));

        return ViewMapper::deltaToWire($page, $type->readableFields($principal));
    }

    private function readableType(\stdClass $body, SyncPrincipal $principal, ?string $scope): SyncableType
    {
        $type = $this->type($body);
        if (! $type->mayRead($principal, $scope)) {
            throw SyncRequestRejected::forbidden();
        }

        return $type;
    }

    private function type(\stdClass $body): SyncableType
    {
        $name = Payload::string($body, 'type');
        try {
            return $this->types->get($name);
        } catch (UnknownSyncableType) {
            throw SyncRequestRejected::unknownType($name);
        }
    }

    private function pageSize(\stdClass $body): int
    {
        $requested = Payload::optionalInt($body, 'page_size') ?? 100;

        return max(1, min($requested, $this->setting('max_page_size', 500)));
    }

    private function setting(string $key, int $default): int
    {
        $value = $this->config->get('sync.api.'.$key);

        return is_int($value) && $value > 0 ? $value : $default;
    }
}
