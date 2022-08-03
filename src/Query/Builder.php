<?php

namespace AHAbid\EloquentCassandra\Query;

use AHAbid\EloquentCassandra\Collection;
use AHAbid\EloquentCassandra\Connection;
use AHAbid\EloquentCassandra\CassandraTypesTrait;
use Illuminate\Database\Query\Builder as BaseBuilder;
use Illuminate\Support\Arr;

class Builder extends BaseBuilder
{
    use CassandraTypesTrait;

    /**
     * Use cassandra filtering
     *
     * @var bool
     */
    public $allowFiltering = false;

    /**
     * Size of fetched page
     *
     * @var null|int
     */
    protected $pageSize = null;

    /**
     * Paginate for page
     *
     * @var null|int
     */
    protected $paginateForPage = null;

    /**
     * Pagination state token
     *
     * @var null|string
     */
    protected $paginationStateToken = null;

    /**
     * @inheritdoc
     */
    public function __construct(Connection $connection, Grammar $grammar = null, Processor $processor = null)
    {
        $this->connection = $connection;
        $this->grammar = $grammar ?: $connection->getQueryGrammar();
        $this->processor = $processor ?: $connection->getPostProcessor();
    }

    /**
     * Support "allow filtering"
     */
    public function allowFiltering($bool = true) {
        $this->allowFiltering = (bool) $bool;

        return $this;
    }

    /**
     * Insert a new record into the database.
     *
     * @param  array  $values
     * @return bool
     */
    public function insert(array $values)
    {
        // Since every insert gets treated like a batch insert, we will make sure the
        // bindings are structured in a way that is convenient when building these
        // inserts statements by verifying these elements are actually an array.
        if (empty($values)) {
            return true;
        }

        if (!is_array(reset($values))) {
            $values = [$values];

            return $this->connection->insert(
                $this->grammar->compileInsert($this, $values),
                $this->cleanBindings(Arr::flatten($values, 1))
            );
        }

        // Here, we'll generate the insert queries for every record and send those
        // for a batch query
        else {
            $queries = [];
            $bindings = [];

            foreach ($values as $key => $value) {
                ksort($value);

                $queries[] = $this->grammar->compileInsert($this, $value);
                $bindings[] = $this->cleanBindings(Arr::flatten($value, 1));
            }

            return $this->connection->insertBulk($queries, $bindings);
        }
    }

    /**
     * Execute the query as a "select" statement.
     *
     * @param  array  $columns
     *
     * @return \Illuminate\Support\Collection
     */
    public function get($columns = ['*'])
    {
        $original = $this->columns;

        if (is_null($original)) {
            $this->columns = $columns;
        }

        //Set up custom options
        $options = [];
        if ($this->pageSize !== null && (int) $this->pageSize > 0) {
            $options['page_size'] = (int) $this->pageSize;
        }
        if ($this->paginationStateToken !== null) {
            $options['paging_state_token'] = $this->paginationStateToken;
        }

        // Process select with custom options
        /** @var \Cassandra\Rows $results */
        $results = $this->processor->processSelect($this, $this->runSelect($options));

        // Make a new collection
        $collection = new Collection();

        if ($this->paginateForPage === null) {
            $this->storeInCollection($collection, $results);

            while (!$results->isLastPage()) {
                $results = $results->nextPage();
                foreach ($results as $row) {
                    $collection->push($row);
                }
            }
        } else {

            $loopingPage = 0;
            while (true) {
                $loopingPage++;
                if ($loopingPage !== $this->paginateForPage) {
                    if ($results->isLastPage()) {
                        break;
                    }

                    $results = $results->nextPage();
                    continue;
                }

                foreach ($results as $row) {
                    $this->storeInCollection($collection, $results);
                }
            }
        }

        $collection->setRowsInstance($results);

        $this->columns = $original;

        return $collection;
    }

    /**
     * Currently we are doing cursor based pagination
     * TODO
     * 1. Implementing mechanism for jumping into a page
     * 2. Taking columns in consideration
     * 
     * @param integer $perPage
     * 
     * @return Collection
     */
    public function paginate($perPage = 15, $columns = ['*'], $pageName = 'page', $page = null)
    {
        $option = ['page_size' => $perPage];
        
        if (!empty($this->paginationStateToken)) {
            $option['paging_state_token'] = $this->paginationStateToken;
        }

        return $this->runSelect($option);
    }

    /**
     * Run the query as a "select" statement against the connection.
     *
     * @param array $options
     *
     * @return array
     */
    protected function runSelect(array $options = [])
    {
        return $this->connection->select(
            $this->toSql(), $this->getBindings(), !$this->useWritePdo, $options
        );
    }

    /**
     * Set pagination state token to fetch
     * next page
     *
     * @param string $token
     *
     * @return Builder
     */
    public function setPaginationStateToken($token = null)
    {
        $this->paginationStateToken = $token;

        return $this;
    }

    /**
     * Set page size
     *
     * @param int $pageSize
     *
     * @return Builder
     */
    public function setPageSize($pageSize = null)
    {
        $this->pageSize = $pageSize !== null ? (int) $pageSize : $pageSize;

        return $this;
    }

    /**
     * Set the limit and offset for a given page.
     *
     * @param  int  $page
     * @param  int  $perPage
     * @return \Illuminate\Database\Query\Builder|static
     */
    public function forPage($page, $perPage = 15)
    {
        $this->paginateForPage = (int) $page;

        return $this->setPageSize($perPage);
    }

    /**
     * Store in Collections
     *
     * @param Collection $collection
     * @param array $results
     * @return Collection
     */
    protected function storeInCollection(Collection $collection, $results)
    {
        foreach ($results as $item) {
            $collection->push($item);
        }

        return $collection;
    }
}
