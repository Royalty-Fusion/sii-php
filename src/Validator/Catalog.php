<?php

declare(strict_types=1);

namespace RoyaltyFusion\Sii\Validator;

/** Catálogos cerrados definidos en SiiTypes_v10.xsd. */
final class Catalog
{
    /** ImpAdicDTEType: códigos de impuestos adicionales y retenciones permitidos en DTE. */
    public const IMPUESTOS_ADICIONALES = [
        '14', '15', '16', '17', '18', '19', '23', '24', '25', '26', '27', '28', '30', '31', '32', '33', '34', '35', '36',
        '37', '38', '39', '40', '41', '44', '45', '46', '47', '48', '49', '50', '51', '52', '53', '54', '55', '271', '301',
        '321', '331', '341', '361', '371', '481',
    ];

    /** TipMonType: glosas de moneda aceptadas. */
    public const MONEDAS = [
        'BOLIVAR', 'BOLIVIANO', 'CHELIN', 'CORONA DIN', 'CORONA NOR', 'CORONA SC', 'CRUZEIRO REAL', 'DIRHAM', 'DOLAR AUST',
        'DOLAR CAN', 'DOLAR HK', 'DOLAR NZ', 'DOLAR SIN', 'DOLAR TAI', 'DOLAR USA', 'DRACMA', 'ESCUDO', 'EURO', 'FLORIN',
        'FRANCO BEL', 'FRANCO FR', 'FRANCO SZ', 'GUARANI', 'LIBRA EST', 'LIRA', 'MARCO AL', 'MARCO FIN', 'NUEVO SOL',
        'OTRAS MONEDAS', 'PESETA', 'PESO', 'PESO CL', 'PESO COL', 'PESO MEX', 'PESO URUG', 'RAND', 'RENMINBI', 'RUPIA',
        'SUCRE', 'YEN',
    ];

    /** MedioPagoType. */
    public const MEDIOS_PAGO = ['CH', 'LT', 'EF', 'PE', 'TC', 'CF', 'OT'];

    public const TIPOS_CUENTA = ['AHORRO', 'CORRIENTE', 'VISTA'];
}
