<?php

declare(strict_types=1);

namespace ZipStream\Option;
require_once $_SERVER["DOCUMENT_ROOT"].'/_h5ai/private/php/ext/myclabs/php-enum/src/Enum.php';


use MyCLabs\Enum\Enum;

/**
 * Methods enum
 *
 * @method static STORE(): Method
 * @method static DEFLATE(): Method
 * @psalm-immutable
 */
class Method extends Enum
{
    public const STORE = 0x00;

    public const DEFLATE = 0x08;
}
