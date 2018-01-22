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

final class ReadCollection implements Type, HasResponse
{
    use ToHttpTrait;

    /**
     * @var string
     */
    private $collectionName;

    /**
     * @var string
     */
    private $result = '{}';

    /**
     * Inspects response
     *
     * @var callable
     */
    private $inspector;

    private function __construct(
        string $collectionName,
        callable $inspector = null
    ) {
        $this->collectionName = $collectionName;
        $this->inspector = $inspector ?: function (Response $response, string $rId = null) {
            return null;
        };
    }

    /**
     * @see https://docs.arangodb.com/3.2/HTTP/Collection/Getting.html#return-information-about-a-collection
     * @see https://docs.arangodb.com/3.2/Manual/DataModeling/Collections/#collection
     *
     * @param string $collectionName
     * @param array $options
     * @return ReadCollection
     */
    public static function with(string $collectionName, array $options = []): ReadCollection
    {
        return new self($collectionName, $options);
    }

    /**
     * @see https://docs.arangodb.com/3.2/HTTP/Collection/Getting.html#return-information-about-a-collection
     * @see https://docs.arangodb.com/3.2/Manual/DataModeling/Collections/#collection
     *
     * @param string $collectionName
     * @param callable $inspector Inspects result, signature is (Response $response, string $rId = null)
     * @param array $options
     * @return ReadCollection
     */
    public static function withInspector(
        string $collectionName,
        callable $inspector,
        array $options = []
    ): ReadCollection {
        return new self($collectionName, $options, $inspector);
    }

    public function checkResponse(Response $response, string $rId = null): ?int
    {
        $this->result = $response->getBody();

        return ($this->inspector)($response, $rId);
    }

    public function collectionName(): string
    {
        return $this->collectionName;
    }

    public function toHttp(): iterable
    {
        return $this->buildAppendBatch(
            HttpHelper::METHOD_GET,
            Urls::URL_COLLECTION . '/' . $this->collectionName,
            []
        );
    }

    public function toJs(): string
    {
        return 'var rId = db._collection("' . $this->collectionName . '");';
    }

    public function rawResult(): ?string
    {
        return $this->result === '{}' ? null : $this->result;
    }

    public function result()
    {
        return json_decode($this->result, true)['result'] ?? null;
    }
}
