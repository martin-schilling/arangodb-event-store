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

final class ReadDocument implements Type, HasResponse
{
    use ToHttpTrait;

    /**
     * @var string
     */
    private $collectionName;

    /**
     * @var array
     */
    private $id;

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
        string $id,
        callable $inspector = null
    ) {
        $this->collectionName = $collectionName;
        $this->id = $id;
        $this->inspector = $inspector ?: function (Response $response, string $rId = null) {
            return null;
        };
    }

    /**
     * @see https://docs.arangodb.com/3.2/HTTP/Document/WorkingWithDocuments.html#read-document
     * @see https://docs.arangodb.com/3.2/Manual/DataModeling/Documents/DocumentMethods.html#document
     *
     * @param string $collectionName
     * @param string $id
     * @return ReadDocument
     */
    public static function with(string $collectionName, string $id): ReadDocument
    {
        return new self($collectionName, $id);
    }

    /**
     * @see https://docs.arangodb.com/3.2/HTTP/Document/WorkingWithDocuments.html#read-document
     * @see https://docs.arangodb.com/3.2/Manual/DataModeling/Documents/DocumentMethods.html#document
     *
     * @param string $collectionName
     * @param string $id
     * @param callable $inspector Inspects result, signature is (Response $response, string $rId = null)
     * @return ReadDocument
     */
    public static function withInspector(
        string $collectionName,
        string $id,
        callable $inspector
    ): ReadDocument {
        return new self($collectionName, $id, $inspector);
    }

    public function collectionName(): string
    {
        return $this->collectionName;
    }

    public function checkResponse(Response $response, string $rId = null): ?int
    {
        $this->result = $response->getBody();

        return ($this->inspector)($response, $rId);
    }

    public function toHttp(): iterable
    {
        return $this->buildAppendBatch(
            HttpHelper::METHOD_GET,
            Urls::URL_DOCUMENT . '/' . $this->collectionName . '/' . $this->id,
            []
        );
    }

    public function toJs(): string
    {
        return 'var rId = db.' . $this->collectionName . '.document(' . $this->id . ');';
    }

    public function rawResult(): ?string
    {
        return $this->result === '{}' ? null : $this->result;
    }

    public function result()
    {
        return json_decode($this->result, true) ?? null;
    }
}
