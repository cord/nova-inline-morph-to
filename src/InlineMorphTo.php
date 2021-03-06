<?php

namespace DigitalCreative\InlineMorphTo;

use App\Nova\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Nova\Fields\BelongsToMany;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Fields\HasMany;
use Laravel\Nova\Fields\HasOne;
use Laravel\Nova\Http\Controllers\CreationFieldController;
use Laravel\Nova\Http\Controllers\ResourceIndexController;
use Laravel\Nova\Http\Controllers\ResourceShowController;
use Laravel\Nova\Http\Controllers\UpdateFieldController;
use Laravel\Nova\Http\Requests\NovaRequest;
use Laravel\Nova\Nova;
use ReflectionClass;

class InlineMorphTo extends Field
{

    /**
     * The field's component.
     *
     * @var string
     */
    public $component = 'inline-morph-to';

    /**
     * Create a new field.
     *
     * @param string $name
     * @param string|callable|null $attribute
     * @param callable|null $resolveCallback
     *
     * @return void
     */
    public function __construct($name, $attribute = null, callable $resolveCallback = null)
    {

        parent::__construct($name, $attribute, $resolveCallback);

        $this->meta = [
            'resources' => [],
            'listable' => true
        ];

    }

    /**
     * Format:
     *
     * [ SomeNovaResource1::class, SomeNovaResource2::class ]
     * [ 'Some Display Text 1' => SomeNovaResource2::class, 'Some Display Text 2' => SomeNovaResource2::class ]
     *
     * @param array $types
     *
     * @return $this
     */
    public function types(array $types): self
    {

        $useKeysAsLabel = Arr::isAssoc($types);

        $types = collect($types)->map(function (string $resource, $key) use ($useKeysAsLabel) {

            /**
             * @var Resource $resourceInstance
             */
            $resourceInstance = new $resource($resource::newModel());

            return [
                'className' => $resource,
                'uriKey' => $resource::uriKey(),
                'label' => $useKeysAsLabel ? $key : $this->convertToHumanCase($resource),
                'fields' => $this->resolveFields($resourceInstance)
            ];

        });

        $this->withMeta([ 'resources' => $types->values() ]);

        return $this;

    }

    private function resolveFields(Resource $resourceInstance): Collection
    {

        /**
         * @var NovaRequest $request
         */
        $request = app(NovaRequest::class);
        $controller = $request->route()->controller;

        switch (get_class($controller)) {

            case CreationFieldController::class :
                return $resourceInstance->creationFields($request);

            case UpdateFieldController::class :
                return $resourceInstance->updateFields($request);

            case ResourceShowController::class :
                return $resourceInstance->detailFields($request);

            case ResourceIndexController::class :
                return $resourceInstance->indexFields($request);

        }

        return $resourceInstance->availableFields($request);

    }

    private function convertToHumanCase(string $resource): string
    {
        return Str::title(str_replace('_', ' ', Str::snake((new ReflectionClass($resource))->getShortName())));
    }

    /**
     * Resolve the given attribute from the given resource.
     *
     * @param mixed $resource
     * @param string $attribute
     *
     * @return mixed
     */
    protected function resolveAttribute($resource, $attribute)
    {
        /**
         * @var null|Model $relationInstance
         * @var Field $field
         */

        if ($relationInstance = $resource->$attribute) {

            $fields = $this->getFields($relationInstance);
            $resource = Nova::resourceForModel($relationInstance);

            foreach ($fields as $field) {

                if ($field instanceof HasOne ||
                    $field instanceof HasMany ||
                    $field instanceof BelongsToMany) {

                    $field->meta[ 'inlineMorphTo' ] = [
                        'viaResourceId' => $relationInstance->id,
                        'viaResource' => $resource::uriKey()
                    ];

                }

                $field->resolve($relationInstance);

            }

            return $resource;

        }

    }

    public function fill(NovaRequest $request, $model)
    {

        /**
         * @var Model $relatedInstance
         * @var Model $model
         * @var Resource $resource
         * @var Field $field
         */

        $resourceClass = $request->input($this->attribute);
        $relatedInstance = $model->{$this->attribute} ?? $resourceClass::newModel();
        $resource = new $resourceClass($relatedInstance);

        if ($relatedInstance->exists) {

            $resource->validateForUpdate($request);

        } else {

            $resource->validateForCreation($request);

        }

        $fields = $this->getFields($relatedInstance);

        foreach ($fields as $field) {

            $field->fill($request, $relatedInstance);

        }

        $relatedInstance->saveOrFail();

        $model->{$this->attribute}()->associate($relatedInstance);

    }

    private function getFields(Model $model): Collection
    {
        $resourceClass = Nova::resourceForModel($model);

        return $this->meta[ 'resources' ]->where('className', $resourceClass)->first()[ 'fields' ];
    }

    public function jsonSerialize()
    {

        /**
         * @var NovaRequest $request
         */
        $request = app(NovaRequest::class);
        $originalResource = $request->route()->resource;

        /**
         * Temporarily remap the route resource key so every sub field thinks its being resolved by its original parent
         */
        foreach ($this->meta[ 'resources' ] as $resource) {

            $resource[ 'fields' ] = $resource[ 'fields' ]->transform(function ($field) use ($request, $resource) {

                $request->route()->setParameter('resource', $resource[ 'uriKey' ]);

                return $field->jsonSerialize();

            });

        }

        $request->route()->setParameter('resource', $originalResource);

        return parent::jsonSerialize();

    }

}
