<?php

declare(strict_types=1);

namespace Flowlight;

use Flowlight\Enums\ContextOperation;
use Flowlight\Enums\ContextStatus;
use Flowlight\Exceptions\ContextFailedError;
use Illuminate\Support\Collection;
use Throwable;
use Traversable;

use function is_array;

/**
 * Context — the execution container for Flowlight pipelines.
 *
 * Holds inputs, params, errors, resources, and metadata as Collections.
 * Designed for clarity and fluent composition.
 *
 * Inspired by LightService (Ruby).
 */
final class Context
{
    /** Captured ErrorInfo for the last raised exception (if any) */
    private ?ErrorInfo $errorInfo = null;

    /** @var Collection<string, mixed> Arbitrary inputs passed into the pipeline */
    private Collection $input;

    /** @var Collection<string, mixed> Validation / business errors (values are arrays or scalars) */
    private Collection $errors;

    /** @var Collection<string, mixed> Additional params */
    private Collection $params;

    /** @var Collection<string, mixed> Arbitrary resources (objects, arrays, etc.) */
    private Collection $resource;

    /** @var Collection<string, mixed> Arbitrary metadata */
    private Collection $meta;

    /** @var Collection<string, mixed> Extra rules (domain-specific) */
    private Collection $extraRules;

    /** @var Collection<string, mixed> Internal-only state not exposed to consumers */
    private Collection $internalOnly;

    /** The action currently being executed (future: narrow to interface) */
    public ?object $invokedAction = null;

    /** Fully qualified class name of current organizer */
    private ?string $currentOrganizer = null;

    /** Fully qualified class name of current action */
    private ?string $currentAction = null;

    /** Current execution status */
    private ContextStatus $status;

    /** @codeCoverageIgnore */
    private function __construct()
    {
        $this->input = collect();
        $this->errors = collect();
        $this->params = collect();
        $this->resource = collect();
        $this->meta = collect();
        $this->extraRules = collect();
        $this->internalOnly = collect();
        $this->status = ContextStatus::INCOMPLETE;
        $this->markUpdateOperation();
    }

    /**
     * Factory with guarded overrides (unknown keys are ignored).
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $overrides  keys: params, errors, resource, extraRules, internalOnly, meta, invokedAction
     */
    public static function makeWithDefaults(array $input = [], array $overrides = []): self
    {
        $ctx = new self;
        $ctx->input = self::toCollection($input);

        $map = [
            'params' => 'params',
            'errors' => 'errors',
            'resource' => 'resource',
            'extraRules' => 'extraRules',
            'internalOnly' => 'internalOnly',
            'meta' => 'meta',
        ];

        foreach ($map as $key => $property) {
            if (\array_key_exists($key, $overrides)) {
                $ctx->{$property} = self::toCollection($overrides[$key]);
            }
        }

        return $ctx;
    }

    // ── Mutators (fluent) ─────────────────────────────────────────────────────────

    /**
     * Merge additional input values (shallow).
     *
     * @param  array<string,mixed>|Collection<string,mixed>  $values
     */
    public function withInputs(array|Collection $values): self
    {
        return $this->addAttrsToContext('input', $values);
    }

    /**
     * Merge additional parameters (shallow).
     *
     * @param  array<string,mixed>|Collection<string,mixed>  $values
     */
    public function withParams(array|Collection $values): self
    {
        return $this->addAttrsToContext('params', $values);
    }

    /**
     * Merge validation or business errors (shallow).
     * Accepts arrays/collections keyed by field; values may be scalars or arrays.
     */
    public function withErrors(mixed $errors): self
    {
        if (empty($errors)) {
            return $this;
        }

        /** @var Collection<string,mixed> $errors */
        $errors = self::toCollection($errors);
        $this->addAttrsToContext('errors', self::toCollection($errors));

        $this->markFailed();

        return $this;
    }

    /**
     * Merge metadata (shallow).
     *
     * @param  array<string,mixed>|Collection<string,mixed>  $values
     */
    public function withMeta(array|Collection $values): self
    {
        return $this->addAttrsToContext('meta', $values);
    }

    /**
     * Merge internal-only data (shallow).
     *
     * @param  array<string,mixed>|Collection<string,mixed>  $values
     */
    public function withInternalOnly(array|Collection $values): self
    {
        return $this->addAttrsToContext('internalOnly', $values);
    }

    /**
     * Add errors and abort execution.
     *
     * If no incoming errors and current errors are empty, attaches $message under "base".
     *
     * @throws ContextFailedError
     */
    public function addErrorsAndAbort(mixed $errors, string $message = 'Context failed due to validation or business errors.'): void
    {
        $incoming = self::toCollection($errors);

        if ($incoming->isNotEmpty()) {
            $this->addAttrsToContext('errors', $incoming);
        }

        if ($incoming->isEmpty() && $this->errors->isEmpty()) {
            $this->addAttrsToContext('errors', ['base' => [$message]]);
        }

        $this->failAndAbort($message);
    }

    /**
     * Store a named resource (object, array, etc.).
     */
    public function withResource(string $name, mixed $value): self
    {
        $this->resource->put($name, $value);

        return $this;
    }

    /**
     * Record the invoked action.
     */
    public function withInvokedAction(object $action): self
    {
        $this->invokedAction = $action;

        return $this;
    }

    // ── Status helpers ────────────────────────────────────────────────────────────

    /** Mark context as complete */
    public function markComplete(): self
    {
        $this->status = ContextStatus::COMPLETE;

        return $this;
    }

    /** Mark context as failed */
    public function markFailed(): self
    {
        $this->status = ContextStatus::FAILED;

        return $this;
    }

    /** Get current status */
    public function status(): ContextStatus
    {
        return $this->status;
    }

    /** True when not FAILED and there are no errors */
    public function success(): bool
    {
        return ! $this->failure();
    }

    /** True when status is FAILED or there are any errors */
    public function failure(): bool
    {
        return $this->status === ContextStatus::FAILED || $this->errors->isNotEmpty();
    }

    // ── Array accessors ───────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function inputArray(): array
    {
        return $this->input->all();
    }

    /** @return array<string,mixed> */
    public function errorsArray(): array
    {
        return $this->errors->all();
    }

    /** @return array<string,mixed> */
    public function paramsArray(): array
    {
        return $this->params->all();
    }

    /** @return array<string,mixed> */
    public function resourceArray(): array
    {
        return $this->resource->all();
    }

    /** @return array<string,mixed> */
    public function metaArray(): array
    {
        return $this->meta->all();
    }

    /** @return array<string,mixed> */
    public function extraRulesArray(): array
    {
        return $this->extraRules->all();
    }

    /** @return array<string,mixed> */
    public function internalOnlyArray(): array
    {
        return $this->internalOnly->all();
    }

    // ── Collection accessors ──────────────────────────────────────────────────────

    /** @return Collection<string,mixed> */
    public function input(): Collection
    {
        return $this->input;
    }

    /** @return Collection<string,mixed> */
    public function errors(): Collection
    {
        return $this->errors;
    }

    /** @return Collection<string,mixed> */
    public function params(): Collection
    {
        return $this->params;
    }

    /** @return Collection<string,mixed> */
    public function resource(): Collection
    {
        return $this->resource;
    }

    /** @return Collection<string,mixed> */
    public function meta(): Collection
    {
        return $this->meta;
    }

    /** @return Collection<string,mixed> */
    public function extraRules(): Collection
    {
        return $this->extraRules;
    }

    /** @return Collection<string,mixed> */
    public function internalOnly(): Collection
    {
        return $this->internalOnly;
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
     * Get the recorded list of successful actions.
     *
     * @return list<string>
     */
    public function successfulActions(): array
    {
        /** @var list<string> */
        $list = (array) ($this->internalOnly->get('successfulActions') ?? []);

        return $list;
    }

    /**
     * Store a structured snapshot of the last failed context.
     * The optional $label defaults to the current action name to aid traceability.
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
            'resource' => $source->resourceArray(),
            'status' => $source->status()->name,
        ]);

        return $this;
    }

    /**
     * Return the snapshot saved by setLastFailedContext(), if any.
     *
     * @return array{
     *   label?: string,
     *   input: array<string,mixed>,
     *   params: array<string,mixed>,
     *   meta: array<string,mixed>,
     *   errors: array<string,mixed>,
     *   resource: array<string,mixed>,
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
             *   resource: array<string,mixed>,
             *   status: string
             * } $value
             */
            return $value;
        }

        return null;
    }

    public function errorInfo(): ?ErrorInfo
    {
        return $this->errorInfo;
    }

    public function organizerName(): ?string
    {
        return $this->currentOrganizer ? self::shortName($this->currentOrganizer) : null;
    }

    public function actionName(): ?string
    {
        return $this->currentAction ? self::shortName($this->currentAction) : null;
    }

    // ── Errors: formatting and exception capture ──────────────────────────────────

    /** Pretty-print errors as JSON */
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
        return $this->withMeta(['operation' => ContextOperation::CREATE]);
    }

    public function markUpdateOperation(): self
    {
        return $this->withMeta(['operation' => ContextOperation::UPDATE]);
    }

    public function createOperation(): bool
    {
        return $this->operation() === ContextOperation::CREATE;
    }

    public function updateOperation(): bool
    {
        return $this->operation() === ContextOperation::UPDATE;
    }

    public function operation(): ContextOperation
    {
        /** @var ContextOperation */
        return $this->meta()->get('operation');
    }

    /**
     * Capture a raised exception, merge its errors if available,
     * and record structured error info in `internalOnly`.
     */
    public function recordRaisedError(Throwable $exception): self
    {
        $this->errorInfo = new ErrorInfo($exception);

        if (\method_exists($exception, 'errors')) {
            /** @var mixed $errs */
            $errs = $exception->errors();
            $this->withErrors($errs);
        }

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

    // ── Failure hook ──────────────────────────────────────────────────────────────

    /**
     * Abort execution by throwing ContextFailedError.
     *
     * @throws ContextFailedError
     */
    protected function failAndAbort(string $message = ''): void
    {
        throw new ContextFailedError(
            $this,
            $message !== '' ? $message : 'Context failed.'
        );
    }

    // ── Internals ────────────────────────────────────────────────────────────────

    /** @return Collection<string,mixed> */
    private static function toCollection(mixed $value): Collection
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

    private static function shortName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    /**
     * Shallow-merge attributes into one of the context collections.
     *
     * @param  'input'|'params'|'errors'|'meta'|'internalOnly'  $target
     * @param  array<string,mixed>|Collection<string,mixed>  $attrs
     */
    private function addAttrsToContext(string $target, array|Collection $attrs): self
    {
        $incoming = self::toCollection($attrs);

        if ($incoming->isEmpty()) {
            return $this;
        }

        /** @var Collection<string,mixed> $current */
        $current = $this->{$target};
        $this->{$target} = $current->merge($incoming);

        return $this;
    }
}
