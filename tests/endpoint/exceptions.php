<?php

/**
 * Excecoes HTTP do GLPI, stubadas. Em arquivo proprio porque um bloco de
 * namespace nao pode coexistir com codigo em escopo global.
 *
 * @license GPL-3.0-or-later
 */

namespace Glpi\Exception\Http;

class NotFoundHttpException extends \RuntimeException
{
}

class AccessDeniedHttpException extends \RuntimeException
{
}
