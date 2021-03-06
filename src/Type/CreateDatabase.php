<?php
/**
 * This file is part of the prooph/arangodb-event-store.
 * (c) 2017-2018 prooph software GmbH <contact@prooph.de>
 * (c) 2017-2018 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\EventStore\ArangoDb\Type;

use ArangoDBClient\HttpHelper;
use ArangoDb\Response;
use ArangoDBClient\Urls;
use Prooph\EventStore\ArangoDb\Exception\LogicException;

class CreateDatabase implements Type
{
    use ToHttpTrait;

    /**
     * @var string
     */
    private $name;

    /**
     * @var array
     */
    private $options;

    /**
     * Inspects response
     *
     * @var callable
     */
    private $inspector;

    private function __construct(
        string $name,
        array $options = [],
        callable $inspector = null
    ) {
        $this->name = $name;
        $this->options = $options;
        $this->inspector = $inspector ?: function (Response $response, string $rId = null) {
            return null;
        };
    }

    /**
     * @see https://docs.arangodb.com/3.2/HTTP/Database/DatabaseManagement.html#create-database
     *
     * @param string $databaseName
     * @param array $options
     * @return CreateDatabase
     */
    public static function with(string $databaseName, array $options = []): CreateDatabase
    {
        return new self($databaseName, $options);
    }

    /**
     * @see https://docs.arangodb.com/3.2/HTTP/Database/DatabaseManagement.html#create-database
     *
     * @param string $databaseName
     * @param callable $inspector Inspects result, signature is (Response $response, string $rId = null)
     * @param array $options
     * @return CreateDatabase
     */
    public static function withInspector(
        string $databaseName,
        callable $inspector,
        array $options = []
    ): CreateDatabase {
        return new self($databaseName, $options, $inspector);
    }

    public function checkResponse(Response $response, string $rId = null): ?int
    {
        return ($this->inspector)($response, $rId);
    }

    public function collectionName(): string
    {
        throw new LogicException('Not possible at the moment, see ArangoDB docs');
    }

    public function toHttp(): iterable
    {
        $options = $this->options;
        $options['name'] = $this->name;

        return $this->buildAppendBatch(
            HttpHelper::METHOD_POST,
            Urls::URL_DATABASE,
            $options
        );
    }

    public function toJs(): string
    {
        throw new LogicException('Not possible at the moment, see ArangoDB docs');
    }
}
