<?php

/**
 * Copiar como `config.php` y completar. `config.php` NO se versiona.
 *
 * Vive fuera de public/ para que el servidor web no lo pueda servir aunque
 * PHP deje de interpretarse por un error de configuración.
 */

return [
    'app' => [
        'nombre'   => 'Sistema de Gestión del Riesgo — Jamundí',
        'version'  => '1.0.0',
        'entorno'  => 'produccion',   // 'local' habilita mensajes de error detallados
        'zona'     => 'America/Bogota',
    ],

    // Los nombres reales de la base y del usuario van en config.php, que no se
    // versiona: este archivo es público y no debe revelar a qué apunta el
    // sistema en producción.
    'db' => [
        'host'     => 'localhost',
        'puerto'   => 3306,
        'nombre'   => 'CUENTA_sgr',
        'usuario'  => 'CUENTA_sgr',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    // Orígenes autorizados para CORS. El frontend es estático y se sirve desde
    // otro host, así que sin esto el navegador bloquea toda llamada a la API.
    'cors' => [
        // En producción la API se sirve bajo /api del mismo dominio que la
        // aplicación, así que no hay petición entre orígenes y esta lista no
        // llega a usarse. Solo hace falta en desarrollo, donde el frontend
        // (5173) y la API (8000) sí son orígenes distintos.
        'origenes' => [
            'http://localhost:5173',
        ],
    ],

    'auth' => [
        // Duración de la sesión en horas.
        'duracion_horas' => 12,
    ],

    'github' => [
        // Token de solo lectura. Se usa desde el servidor para que nunca viaje
        // al navegador. Un repositorio público funciona sin token, pero con él
        // el límite de peticiones sube de 60 a 5000 por hora.
        'token' => '',

        // El sistema se desarrolla en dos frentes y cada uno vive en su propia
        // rama. Se leen ambas y se funden en una sola línea de tiempo: con una
        // sola, media historia del sistema quedaría invisible.
        'fuentes' => [
            [
                'owner' => 'miltonf10',
                'repo' => 'rufe-jamundi',
                'branch' => 'main',
                'etiqueta' => 'Tablero RUFE',
            ],
            [
                'owner' => 'miltonf10',
                'repo' => 'rufe-jamundi',
                'branch' => 'sistema-gestion-riesgo',
                'etiqueta' => 'Plataforma',
            ],
        ],
    ],

    // Clave de un solo uso para ejecutar bin/install.php. Vaciar tras instalar.
    'install_key' => '',
];
