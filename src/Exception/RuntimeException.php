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

namespace Prooph\EventStore\ArangoDb\Exception;

use ArangoDb\RequestFailedException;
use Prooph\EventStore\ArangoDb\Type\Type;
use Prooph\EventStore\Exception\RuntimeException as EventStoreRuntimeException;

class RuntimeException extends EventStoreRuntimeException implements ArangoDbEventStoreException
{
    public static function fromErrorResponse(string $body, Type $type)
    {
        $data = json_decode($body, true) ?: [];

        return new self(sprintf(
                'Code: %s Error Number: %s Error Message: %s Type: %s Raw: %s',
                $data['code'] ?? '',
                $data['errorNum'] ?? '',
                $data['errorMessage'] ?? '',
                get_class($type),
                $body
            )
        );
    }

    public static function fromServerException(RequestFailedException $e)
    {
        return new self($e->getMessage(), $e->getCode(), $e);
    }
}
