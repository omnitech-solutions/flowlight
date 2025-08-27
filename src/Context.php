<?php

declare(strict_types=1);

namespace Flowlight;

use Flowlight\Enums\ContextOperation;
use Flowlight\Enums\ContextStatus;
use Flowlight\Utils\ObjectUtils;
use Illuminate\Support\Collection;
use Throwable;
use Traversable;

use function is_array;

/**
 * Context — execution container for Flowlight pipelines.
 */
final class Context
{
    /** Captured ErrorInfo for the last raised exception (if any). */
    private ?ErrorInfo $errorInfo = null;

    /** @var Collection<string, mixed> Arbitrary inputs passed into the pipeline. */
    private Collection $input;

    /**
     * @var Collection<string, mixed> Validation/business errors keyed by attribute or domain key.
     */
    private Collection $errors;

    /** @var Collection<string, mixed> Additional parameters. */
    private Collection $params;

    /** @var Collection<string, mixed> Arbitrary resources (objects, arrays, DTOs). */
    private Collection $resources;

    /** @var Collection<string, mixed> Arbitrary metadata (e.g., operation). */
    private Collection $meta;

    /** @var Collection<string, mixed> Extra rules for domain-specific validation. */
    private Collection $extraRules;

    /**
     * @var Collection<int,string> List of dotted keys to omit from validation rules.
     */
    private Collection $dottedOmitRules;

    /** @var Collection<string, mixed> Internal-only state not exposed to consumers. */
    private Collection $internalOnly;

    /** The action instance currently being executed. */
    public ?object $invokedAction = null;

    /** Fully-qualified class name of the current organizer. */
    private ?string $currentOrganizer = null;

    /** Fully-qualified class name of the current action. */
    private ?string $currentAction = null;

    /** Current execution status (defaults to INCOMPLETE). */
    private ContextStatus $status = ContextStatus::INCOMPLETE;

    /** Whether this context was intentionally aborted (independent of errors/status). */
    private bool $aborted = false;

    /** @codeCoverageIgnore */
    private function __construct()
    {
        $this->input = collect();
        $this->errors = collect();
        $this->params = collect();
        $this->resources = collect();
        $this->meta = collect();
        $this->extraRules = collect();

        /** @var Collection<int,string> $emptyStringList */
        $emptyStringList = collect();
        $this->dottedOmitRules = $emptyStringList;
        $this->internalOnly = collect();
        $this->markUpdateOperation();
    }

    /**
     * Create a Context with default empty Collections and optional whitelisted overrides.
     *
     * Recognized override keys: params, errors, resource, extraRules, dottedOmitRules, internalOnly, meta, invokedAction.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $overrides
     */
    public static function makeWithDefaults(array $input = [], array $overrides = []): self
    {
        $ctx = new self;
        $ctx->input = self::asCollection($input);

        $map = [
            'params' => 'params',
            'errors' => 'errors',
            'resources' => 'resources',
            'extraRules' => 'extraRules',
            'internalOnly' => 'internalOnly',
            'meta' => 'meta',
        ];

        foreach ($map as $key => $property) {
            if (\array_key_exists($key, $overrides)) {
                /** @var Collection<string,mixed> $current */
                $current = $ctx->{$property};
                $ctx->{$property} = $current->merge(self::asCollection($overrides[$key]));
            }
        }

        // dottedOmitRules is a list<string>; normalize and REPLACE (not merge)
        if (\array_key_exists('dottedOmitRules', $overrides)) {
            /** @var mixed $raw */
            $raw = $overrides['dottedOmitRules'];

            /** @var array<int,mixed>|Collection<int,mixed>|string|null $rawSafe */
            $rawSafe = (\is_array($raw) || $raw instanceof Collection || \is_string($raw) || $raw === null)
                ? $raw
                : null;

            // normalizeKeyList returns list<string>|null
            $list = ObjectUtils::normalizeKeyList($rawSafe) ?? [];

            /** @var \Illuminate\Support\Collection<int,string> $col */
            $col = collect($list);

            $ctx->dottedOmitRules = $col;
        }

        return $ctx;
    }

    /**
     * Snapshot of context suitable for external consumers.
     *
     * When $onlyDottedKeys is provided (string or list/Collection of strings),
     * returns a collection keyed by those dotted paths, defaulting missing paths to null.
     *
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return Collection<string,mixed>
     */
    public function toCollection(string|array|Collection|null $onlyDottedKeys = null): Collection
    {
        $snapshot = collect([
            // core payloads
            'input' => $this->inputArray(),
            'params' => $this->paramsArray(),
            'meta' => $this->metaArray(),
            'errors' => $this->errorsArray(),
            'resources' => $this->resourcesArray(),

            // structured error info (if any), from internalOnly
            'errorInfo' => $this->internalOnly()->get('errorInfo'),

            // meta
            'organizer' => $this->organizerName(),
            'action' => $this->actionName(),
            'status' => $this->status()->name,
            'aborted' => $this->aborted(),
            'success' => $this->success(),
            'failure' => $this->failure(),
            'operation' => $this->operation(),
        ]);

        return $this->selectKeys($snapshot, $onlyDottedKeys);
    }

    /**
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return array<string,mixed>
     */
    public function toArray(string|array|Collection|null $onlyDottedKeys = null): array
    {
        return $this->toCollection($onlyDottedKeys)->toArray();
    }

    // ── Mutators (fluent) ─────────────────────────────────────────────────────────

    /**
     * Shallow-merge additional input values.
     *
     * @param  array<string,mixed>|Collection<string,mixed>  $values
     */
    public function withInputs(array|Collection $values): self
    {
        return $this->addAttrsToContext('input', $values);
    }

    /**
     * Shallow-merge additional parameters.
     *
     * @param  array<string,mixed>|Collection<string,mixed>  $values
     */
    public function withParams(array|Collection $values): self
    {
        return $this->addAttrsToContext('params', $values);
    }

    /**
     * Shallow-merge validation/business errors and mark the context as aborted.
     */
    public function withErrors(mixed $errors): self
    {
        if (empty($errors)) {
            return $this;
        }

        /** @var Collection<string,mixed> $errors */
        $errors = self::asCollection($errors);
        $this->addAttrsToContext('errors', $errors);

        $this->abort();

        return $this;
    }

    /**
     * Shallow-merge metadata.
     *
     * @param  array<string,mixed>|Collection<string,mixed>  $values
     */
    public function withMeta(array|Collection $values): self
    {
        return $this->addAttrsToContext('meta', $values);
    }

    /**
     * Shallow-merge internal-only data (not for API consumers).
     *
     * @param  array<string,mixed>|Collection<string,mixed>  $values
     */
    public function withInternalOnly(array|Collection $values): self
    {
        return $this->addAttrsToContext('internalOnly', $values);
    }

    /**
     * Shallow-merge resource data.
     *
     * @param  array<string,mixed>|Collection<string,mixed>  $values
     */
    public function withResources(array|Collection $values): self
    {
        $this->addAttrsToContext('resources', self::asCollection($values));

        return $this;
    }

    /**
     * Set a single resource value by (dot-notated) key.
     *
     * @return $this
     */
    public function withResource(string $key, mixed $value): self
    {
        /** @var array<string,mixed> $payload */
        $payload = $this->resourcesArray();
        data_set($payload, $key, $value);

        $this->resources = collect((array) $payload);

        return $this;
    }

    /**
     * Record the invoked action instance for observability.
     */
    public function withInvokedAction(object $action): self
    {
        $this->invokedAction = $action;

        return $this;
    }

    // ── Status helpers ────────────────────────────────────────────────────────────

    /** Mark the context as COMPLETE. */
    public function markComplete(): self
    {
        $this->status = ContextStatus::COMPLETE;

        return $this;
    }

    /** Get the current execution status. */
    public function status(): ContextStatus
    {
        return $this->status;
    }

    /** Intentionally abort processing (independent of status). */
    public function abort(): self
    {
        $this->aborted = true;

        return $this;
    }

    /** Whether this context was intentionally aborted. */
    public function aborted(): bool
    {
        return $this->aborted;
    }

    /** True when not aborted and there are no recorded errors. */
    public function success(): bool
    {
        return ! $this->failure();
    }

    /** True when aborted or any errors are present. */
    public function failure(): bool
    {
        return $this->aborted || $this->errors->isNotEmpty();
    }

    /** Whether status is COMPLETE. */
    public function isComplete(): bool
    {
        return $this->status === ContextStatus::COMPLETE;
    }

    /** Whether status is INCOMPLETE. */
    public function isIncomplete(): bool
    {
        return $this->status === ContextStatus::INCOMPLETE;
    }

    // ── Array accessors ───────────────────────────────────────────────────────────

    /**
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return array<string,mixed>
     */
    public function inputArray(string|array|Collection|null $onlyDottedKeys = null): array
    {
        return $this->input($onlyDottedKeys)->all();
    }

    /**
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return array<string,mixed>
     */
    public function errorsArray(string|array|Collection|null $onlyDottedKeys = null): array
    {
        return $this->errors($onlyDottedKeys)->all();
    }

    /**
     * paramsArray(..) calls params(..)->all()
     *
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return array<string,mixed>
     */
    public function paramsArray(string|array|Collection|null $onlyDottedKeys = null): array
    {
        return $this->params($onlyDottedKeys)->all();
    }

    /**
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return array<string,mixed>
     */
    public function resourcesArray(string|array|Collection|null $onlyDottedKeys = null): array
    {
        return $this->resources($onlyDottedKeys)->all();
    }

    /**
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return array<string,mixed>
     */
    public function metaArray(string|array|Collection|null $onlyDottedKeys = null): array
    {
        return $this->meta($onlyDottedKeys)->all();
    }

    /** @return array<string,mixed> */
    public function extraRulesArray(): array
    {
        return $this->extraRules->all();
    }

    /** @return list<string> */
    public function dottedOmitRulesArray(): array
    {
        /** @var list<string> */
        return $this->dottedOmitRules->all();
    }

    /**
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return array<string,mixed>
     */
    public function internalOnlyArray(string|array|Collection|null $onlyDottedKeys = null): array
    {
        return $this->internalOnly($onlyDottedKeys)->all();
    }

    // ── Collection accessors ──────────────────────────────────────────────────────

    /**
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return Collection<string,mixed>
     */
    public function input(string|array|Collection|null $onlyDottedKeys = null): Collection
    {
        return $this->selectKeys($this->input, $onlyDottedKeys);
    }

    /**
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return Collection<string,mixed>
     */
    public function errors(string|array|Collection|null $onlyDottedKeys = null): Collection
    {
        return $this->selectKeys($this->errors, $onlyDottedKeys);
    }

    /**
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return Collection<string,mixed>
     */
    public function params(string|array|Collection|null $onlyDottedKeys = null): Collection
    {
        return $this->selectKeys($this->params, $onlyDottedKeys);
    }

    /**
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return Collection<string,mixed>
     */
    public function resources(string|array|Collection|null $onlyDottedKeys = null): Collection
    {
        return $this->selectKeys($this->resources, $onlyDottedKeys);
    }

    /**
     * Fetch a single resource value by (dot-notated) key.
     */
    public function resource(string $dottedKey): mixed
    {
        return data_get($this->resourcesArray(), $dottedKey);
    }

    /**
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return Collection<string,mixed>
     */
    public function meta(string|array|Collection|null $onlyDottedKeys = null): Collection
    {
        return $this->selectKeys($this->meta, $onlyDottedKeys);
    }

    /** @return Collection<string,mixed> */
    public function extraRules(): Collection
    {
        return $this->extraRules;
    }

    /** @return Collection<int,string> */
    public function dottedOmitRules(): Collection
    {
        /** @var Collection<int,string> $col */
        $col = collect($this->dottedOmitRulesArray());

        return $col;
    }

    /**
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return Collection<string,mixed>
     */
    public function internalOnly(string|array|Collection|null $onlyDottedKeys = null): Collection
    {
        return $this->selectKeys($this->internalOnly, $onlyDottedKeys);
    }

    // ── Organizer / action names ─────────────────────────────────────────────────

    public function setCurrentOrganizer(string $fqcn): self
    {
        $this->currentOrganizer = $fqcn;

        return $this;
    }

    public function setCurrentAction(string $fqcn): self
    {
        $this->currentAction = $fqcn;

        return $this;
    }

    /**
     * Record a successfully executed action class name (or label such as "callable").
     */
    public function addSuccessfulAction(string $actionClassName): self
    {
        /** @var array<int,string> $list */
        $list = (array) ($this->internalOnly->get('successfulActions') ?? []);
        $list[] = $actionClassName;
        $this->internalOnly->put('successfulActions', array_values(array_unique($list)));

        return $this;
    }

    /**
     * @return list<string>
     */
    public function successfulActions(): array
    {
        /** @var list<string> $list */
        $list = (array) ($this->internalOnly->get('successfulActions') ?? []);

        return $list;
    }

    /**
     * Store a structured snapshot of a failed context for later inspection.
     *
     * @return $this
     */
    public function setLastFailedContext(Context $source, ?string $label = null): self
    {
        $this->internalOnly->put('lastFailedContext', [
            'label' => $label ?? $source->actionName(),
            'input' => $source->inputArray(),
            'params' => $source->paramsArray(),
            'meta' => $source->metaArray(),
            'errors' => $source->errorsArray(),
            'resources' => $source->resourcesArray(),
            'status' => $source->status()->name,
        ]);

        return $this;
    }

    /**
     * @return array{
     *   label?: string,
     *   input: array<string,mixed>,
     *   params: array<string,mixed>,
     *   meta: array<string,mixed>,
     *   errors: array<string,mixed>,
     *   resources: array<string,mixed>,
     *   status: string
     * }|null
     */
    public function lastFailedContext(): ?array
    {
        $value = $this->internalOnly->get('lastFailedContext');

        if (\is_array($value)) {
            /** @var array{
             *   label?: string,
             *   input: array<string,mixed>,
             *   params: array<string,mixed>,
             *   meta: array<string,mixed>,
             *   errors: array<string,mixed>,
             *   resources: array<string,mixed>,
             *   status: string
             * } $value
             */
            return $value;
        }

        return null;
    }

    /** The last captured ErrorInfo from recordRaisedError(), if any. */
    public function errorInfo(): ?ErrorInfo
    {
        return $this->errorInfo;
    }

    /** Short organizer class name, if set. */
    public function organizerName(): ?string
    {
        return $this->currentOrganizer ? self::shortName($this->currentOrganizer) : null;
    }

    /** Short action class name, if set. */
    public function actionName(): ?string
    {
        return $this->currentAction ? self::shortName($this->currentAction) : null;
    }

    // ── Errors: formatting and exception capture ──────────────────────────────────

    /** Pretty-print current errors as JSON (UTF-8, pretty). */
    public function formattedErrors(): string
    {
        /** @var string $json */
        $json = (string) json_encode($this->errors->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        return $json;
    }

    // ---------------------------------------------------------------------
    // Operation helpers (stored in meta['operation'])
    // ---------------------------------------------------------------------

    public function markCreateOperation(): self
    {
        return $this->withMeta(['operation' => ContextOperation::CREATE->value]);
    }

    public function markUpdateOperation(): self
    {
        return $this->withMeta(['operation' => ContextOperation::UPDATE->value]);
    }

    public function createOperation(): bool
    {
        return $this->operation() === ContextOperation::CREATE->value;
    }

    public function updateOperation(): bool
    {
        return $this->operation() === ContextOperation::UPDATE->value;
    }

    /**
     * @return string One of ContextOperation::*->value
     */
    public function operation(): string
    {
        /** @var string */
        return $this->meta()->get('operation') ?? ContextOperation::UPDATE->value;
    }

    /**
     * Capture a raised exception, merge its validation errors if available,
     * and record structured error info in internalOnly['errorInfo'].
     */
    public function recordRaisedError(Throwable $exception): self
    {
        $this->errorInfo = new ErrorInfo($exception);

        if (\method_exists($exception, 'errors')) {
            /** @var mixed $errs */
            $errs = $exception->errors();
            $this->withErrors($errs);
        }

        // ⬇️ use ErrorInfo::toCollection(), not asCollection()
        $summary = $this->errorInfo
            ->toCollection()
            ->merge([
                'organizer' => $this->organizerName(),
                'actionName' => $this->actionName(),
            ])
            ->all();

        $this->internalOnly->put('errorInfo', $summary);

        return $this;
    }

    // ── Internals ────────────────────────────────────────────────────────────────

    /**
     * Normalize input into a Collection.
     * - Collection → returned as-is
     * - array|Traversable → collected
     * - other → empty Collection
     *
     * @return Collection<string,mixed>
     */
    private static function asCollection(mixed $value): Collection
    {
        if ($value instanceof Collection) {
            return $value;
        }
        if (is_array($value)) {
            return collect($value);
        }
        if ($value instanceof Traversable) {
            return collect(iterator_to_array($value));
        }

        return collect();
    }

    /** Extract the short (basename) of a fully-qualified class name. */
    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /**
     * Select dotted keys from a collection (defaults to the entire collection when no keys).
     * Missing keys are included with null values.
     *
     * @param  Collection<string,mixed>  $source
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return Collection<string,mixed>
     */
    /**
     * @param  Collection<string,mixed>  $source
     * @param  string|array<int,string>|Collection<int,string>|null  $onlyDottedKeys
     * @return Collection<string,mixed>
     */
    private function selectKeys(Collection $source, string|array|Collection|null $onlyDottedKeys = null): Collection
    {
        $list = ObjectUtils::normalizeKeyList($onlyDottedKeys);
        if ($list === null) {
            return $source;
        }

        /** @var array<string,mixed> $array */
        $array = $source->all();

        /** @var array<string,mixed> $out */
        $out = ObjectUtils::pickOrNull($array, $list);

        return collect($out);
    }

    /**
     * Shallow-merge attributes into one of the context Collections.
     *
     * @param  'input'|'params'|'errors'|'meta'|'internalOnly'|'resources'  $target
     * @param  array<string,mixed>|Collection<string,mixed>  $attrs
     * @return $this
     */
    private function addAttrsToContext(string $target, array|Collection $attrs): self
    {
        $incoming = self::asCollection($attrs);

        if ($incoming->isEmpty()) {
            return $this;
        }

        /** @var Collection<string,mixed> $current */
        $current = $this->{$target};
        $this->{$target} = $current->merge($incoming);

        return $this;
    }
}
