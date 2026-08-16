<?php

/**
 * Teste da logica de CIDR, executavel sem GLPI:
 *   docker run --rm -v "$PWD":/app -w /app php:8.3-cli php tests/network_filter_test.php
 */

require __DIR__ . '/../wallpaper/src/NetworkFilter.php';

use GlpiPlugin\Wallpaper\NetworkFilter;

$failures = 0;
$checks   = 0;

function check(string $label, $actual, $expected): void
{
    global $failures, $checks;
    $checks++;
    if ($actual !== $expected) {
        $failures++;
        printf("FALHOU: %s (esperado %s, obtido %s)\n", $label, var_export($expected, true), var_export($actual, true));
    }
}

// Lista vazia libera tudo (padrao do plugin).
check('vazio libera', NetworkFilter::isAllowed('8.8.8.8', ''), true);
check('so espacos libera', NetworkFilter::isAllowed('8.8.8.8', '   '), true);

// IPv4 dentro e fora da faixa.
check('10.1.2.3 em 10.0.0.0/8', NetworkFilter::isAllowed('10.1.2.3', '10.0.0.0/8'), true);
check('11.1.2.3 fora de 10.0.0.0/8', NetworkFilter::isAllowed('11.1.2.3', '10.0.0.0/8'), false);
check('192.168.1.5 em /16', NetworkFilter::isAllowed('192.168.1.5', '192.168.0.0/16'), true);
check('192.169.1.5 fora de /16', NetworkFilter::isAllowed('192.169.1.5', '192.168.0.0/16'), false);

// Prefixo que nao termina em byte cheio: exercita a mascara parcial.
check('10.0.1.1 em 10.0.0.0/23', NetworkFilter::isAllowed('10.0.1.1', '10.0.0.0/23'), true);
check('10.0.2.1 fora de 10.0.0.0/23', NetworkFilter::isAllowed('10.0.2.1', '10.0.0.0/23'), false);
check('172.16.5.9 em 172.16.0.0/12', NetworkFilter::isAllowed('172.16.5.9', '172.16.0.0/12'), true);
check('172.32.5.9 fora de 172.16.0.0/12', NetworkFilter::isAllowed('172.32.5.9', '172.16.0.0/12'), false);

// /32 e IP avulso.
check('/32 exato', NetworkFilter::isAllowed('10.0.0.7', '10.0.0.7/32'), true);
check('/32 vizinho', NetworkFilter::isAllowed('10.0.0.8', '10.0.0.7/32'), false);
check('IP avulso sem barra', NetworkFilter::isAllowed('10.0.0.7', '10.0.0.7'), true);
check('IP avulso vizinho', NetworkFilter::isAllowed('10.0.0.8', '10.0.0.7'), false);

// /0 libera tudo explicitamente.
check('0.0.0.0/0 libera', NetworkFilter::isAllowed('203.0.113.9', '0.0.0.0/0'), true);

// Multiplas entradas e separadores variados.
check('lista com virgula', NetworkFilter::isAllowed('192.168.4.4', '10.0.0.0/8, 192.168.0.0/16'), true);
check('lista com quebra de linha', NetworkFilter::isAllowed('192.168.4.4', "10.0.0.0/8\n192.168.0.0/16"), true);
check('fora de todas', NetworkFilter::isAllowed('203.0.113.1', '10.0.0.0/8, 192.168.0.0/16'), false);

// IPv6.
check('IPv6 dentro', NetworkFilter::isAllowed('2001:db8::1', '2001:db8::/32'), true);
check('IPv6 fora', NetworkFilter::isAllowed('2001:db9::1', '2001:db8::/32'), false);

// IPv4 e IPv6 nao se misturam.
check('IPv4 nao casa regra IPv6', NetworkFilter::isAllowed('10.0.0.1', '::/0'), false);
check('IPv6 nao casa regra IPv4', NetworkFilter::isAllowed('2001:db8::1', '0.0.0.0/0'), false);

// Entrada malformada nao deve liberar acesso por acidente.
check('regra invalida nao libera', NetworkFilter::isAllowed('10.0.0.1', 'nao-e-um-ip'), false);
check('prefixo grande demais nao libera', NetworkFilter::isAllowed('10.0.0.1', '10.0.0.0/33'), false);
check('IP do cliente invalido nao passa', NetworkFilter::isAllowed('nao-e-ip', '10.0.0.0/8'), false);

// Validacao de sintaxe exibida ao administrador.
check('detecta invalidos', NetworkFilter::findInvalid(['10.0.0.0/8', 'xxx', '10.0.0.0/33']), ['xxx', '10.0.0.0/33']);
check('nada invalido', NetworkFilter::findInvalid(['10.0.0.0/8', '2001:db8::/32', '1.2.3.4']), []);

// getClientIp: X-Forwarded-For so vale vindo de proxy cadastrado.
$_SERVER['REMOTE_ADDR']          = '10.20.0.5';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.7, 10.20.0.5';
check('XFF aceito de proxy confiavel', NetworkFilter::getClientIp('10.20.0.5'), '203.0.113.7');
check('XFF ignorado sem proxy configurado', NetworkFilter::getClientIp(''), '10.20.0.5');
check('XFF ignorado de proxy nao confiavel', NetworkFilter::getClientIp('10.99.0.1'), '10.20.0.5');

$_SERVER['HTTP_X_FORWARDED_FOR'] = 'nao-e-ip';
check('XFF forjado invalido cai no REMOTE_ADDR', NetworkFilter::getClientIp('10.20.0.5'), '10.20.0.5');

unset($_SERVER['HTTP_X_FORWARDED_FOR']);
check('sem XFF usa REMOTE_ADDR', NetworkFilter::getClientIp('10.20.0.5'), '10.20.0.5');

printf("\n%d verificacoes, %d falhas\n", $checks, $failures);
exit($failures === 0 ? 0 : 1);
