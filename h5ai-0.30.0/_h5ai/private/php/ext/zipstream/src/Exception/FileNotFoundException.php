<?php
declare(strict_types=1);

namespace ZipStream\Exception;

require_once $_SERVER["DOCUMENT_ROOT"].'/_h5ai/private/php/ext/zipstream/src/Exception.php';

use ZipStream\Exception;

/**
 * This Exception gets invoked if a file wasn't found
 */
class FileNotFoundException extends Exception
{
    /**
     * Constructor of the Exception
     *
     * @param String $path - The path which wasn't found
     */
    public function __construct(string $path)
    {
        parent::__construct("The file with the path $path wasn't found.");
    }
}
