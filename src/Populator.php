<?php

namespace Greabock\Populator;

use Exception;
use Greabock\Populator\Relation\BelongsToManyPopulator;
use Greabock\Populator\Relation\BelongsToPopulator;
use Greabock\Populator\Relation\HasManyPopulator;
use Greabock\Populator\Relation\HasOnePopulator;
use Greabock\Populator\Relation\MorphManyPopulator;
use Greabock\Populator\Relation\MorphOnePopulator;
use Greabock\Populator\Relation\MorphToManyPopulator;
use Greabock\Populator\Relation\MorphToPopulator;
use Greabock\Populator\Relation\RelationPopulator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class Populator
{
    /**
     * @var Resolver
     */
    private $resolver;

    /**
     * @var RelationPopulator[]
     */
    protected $relationPopulators = [];
    /**
     * @var UnitOfWork
     */
    private $uow;

    /**
     * Populator constructor.
     * @param Resolver $resolver
     * @param UnitOfWork $uow
     */
    public function __construct(Resolver $resolver, UnitOfWork $uow)
    {
        $this->resolver = $resolver;
        $this->uow = $uow;
        $this->initRelationPopulators();
    }

    protected function getRelations(Model $model, array $data)
    {
        $allFields = Arr::except($data, $model->getFillable());

        $relations = [];

        foreach ($allFields as $relation => $relationData) {

            $relation = static::camel($relation);

            if (method_exists($model, $relation) && call_user_func([$model, $relation]) instanceof Relation) {

                $relations[$relation] = $relationData;
            }
        }
        return $relations;
    }

    protected function clearData(array &$data, array $relations)
    {
        array_walk($relations, function ($value, $key) use (&$data) {
            unset($data[$key]);
        });
    }

    /**
     * @param mixed|Model|string $model
     * @param array|null $data
     * @param array $params
     * @return Model|null
     */
    public function populate($model, ?array $data, array $params = []): ?Model
    {
        assert(is_subclass_of($model, Model::class));

        if (is_null($data)) {
            return null;
        }

        if (is_string($model)) {
            $model = $this->resolve($model, $data);
        }

        $this->fill($model, $data);

        $this->fillRelations($model, $data);

        $this->uow->persist($model);

        return $model;
    }

    /**
     * @param Model $model
     * @param array $data
     */
    protected function fill(Model $model, array $data)
    {
        // remove from data relation fields
        $this->clearData($data, $this->getRelations($model, $data));

        $model->fill($data);
    }

    /**
     * @param $model
     * @param $relationName
     * @return RelationPopulator
     */
    public function getRelationPopulator($model, $relationName)
    {
        if (is_string($model)) {
            $model = new $model;
        }

        $relation = $this->extractRelation($model, $relationName);
        foreach ($this->relationPopulators as $class => $populator) {
            if ($relation instanceof $class) {
                return $populator;
            }
        }
    }

    /**
     * @throws Exception
     */
    public function flush(): void
    {
        $this->uow->flush();
    }

    /**
     * @param $model
     * @param $data
     * @return Model
     */
    public function resolve(string $model, array $data): Model
    {
        return $this->resolver->resolve($model, $data);
    }

    /**
     * @param Model $model
     * @param array $data
     */
    protected function fillRelations(Model $model, array $data): void
    {
        foreach ($this->getRelations($model, $data) as $relation => $relationData) {
            $this->populateRelation($model, $relation, $relationData);
        }
    }

    /**
     * @param Model $model
     * @param string $relationName
     * @param array $relationData
     */
    protected function populateRelation(Model $model, string $relationName, ?array $relationData): void
    {
        $this->getRelationPopulator($model, $relationName)
            ->populate($model, $this->extractRelation($model, $relationName), $relationData, $relationName);
    }

    private function initRelationPopulators(): void
    {
        $this->relationPopulators = [
            MorphTo::class       => new MorphToPopulator($this->resolver, $this->uow, $this),
            HasMany::class       => new HasManyPopulator($this->resolver, $this->uow, $this),
            BelongsToMany::class => new BelongsToManyPopulator($this->resolver, $this->uow, $this),
            BelongsTo::class     => new BelongsToPopulator($this->resolver, $this->uow, $this),
            HasOne::class        => new HasOnePopulator($this->resolver, $this->uow, $this),
            MorphOne::class      => new MorphOnePopulator($this->resolver, $this->uow, $this),
            MorphMany::class     => new MorphManyPopulator($this->resolver, $this->uow, $this),
            MorphToMany::class   => new MorphToManyPopulator($this->resolver, $this->uow, $this),
        ];
    }

    private function extractRelation(Model $model, string $relationName): Relation
    {
        return call_user_func([$model, $relationName]);
    }

    /**
     * @param string|int $str
     * @return bool|mixed
     */
    public static function camel($str)
    {
        return Str::startsWith($str, '__') ? '__' . Str::camel($str) : Str::camel($str);
    }
}
