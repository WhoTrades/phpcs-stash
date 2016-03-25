<?php
/**
 * @author Artem Naumenko
 *
 * Atlassian Stash can't send more then 1mb json response, and send just first 1mb of data. At this case
 * we receive error "cURL error 56: Problem (3) in the Chunked-Encoded data", and they will be translated to
 * this exeption
 */
namespace PhpCsStash\Exception;

class Runtime extends \Exception
{

}
