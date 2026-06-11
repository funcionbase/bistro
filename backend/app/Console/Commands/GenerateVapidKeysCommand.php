<?php

namespace App\Console\Commands;

use Base64Url\Base64Url;
use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;
use RuntimeException;

/**
 * Genera un par de claves VAPID (Voluntary Application Server Identification)
 * para Web Push (#149). Las claves son curva P-256 codificadas en base64url.
 *
 * Bootstrap único por entorno:
 *   $ php artisan push:generate-vapid-keys
 *   → Pega las dos líneas en `.env` (`VAPID_PUBLIC_KEY` y `VAPID_PRIVATE_KEY`)
 *
 * Importante:
 *  - Las claves NO rotan automáticamente. Rotarlas invalida todas las subs
 *    existentes (los navegadores tienen que re-suscribirse vía
 *    `pushsubscriptionchange` listener del SW).
 *  - La clave pública NO es secreta — se expone a todo cliente vía Inertia
 *    shared prop `vapidPublicKey`. La privada SÍ es secreta — en PDN debe
 *    vivir en SSM Parameter Store, nunca en repo.
 *
 * Fallback Windows:
 *  - `VAPID::createVapidKeys()` usa internamente `openssl_pkey_new` con
 *    `OPENSSL_KEYTYPE_EC`, que falla en builds de PHP Windows sin
 *    `openssl.cnf` configurado (ECKey.php:98 → "Unable to create the key").
 *  - El fallback usa la CLI `openssl ecparam` + `openssl_pkey_get_private`,
 *    que sí leen EC keys sin necesidad de crearlas desde dentro de PHP.
 */
class GenerateVapidKeysCommand extends Command
{
    /** @var string */
    protected $signature = 'push:generate-vapid-keys';

    /** @var string */
    protected $description = 'Genera un par de claves VAPID para Web Push (CA1 #149).';

    public function handle(): int
    {
        try {
            $keys = VAPID::createVapidKeys();
        } catch (RuntimeException $e) {
            $this->warn('OpenSSL nativo de PHP no pudo crear EC keys ('.$e->getMessage().'). Probando fallback con OpenSSL CLI...');
            try {
                $keys = $this->generateWithOpensslCli();
            } catch (RuntimeException $cliErr) {
                $this->error('Fallback CLI también falló: '.$cliErr->getMessage());
                $this->comment('Asegurate de tener `openssl` en tu PATH. En Linux es nativo; en Windows viene con Git Bash.');

                return self::FAILURE;
            }
        }

        $this->info('VAPID keys generadas. Pega estas líneas en tu .env:');
        $this->newLine();
        $this->line('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $this->line('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $this->newLine();
        $this->comment('Recordá:');
        $this->comment(' - VAPID_PUBLIC_KEY se expone al cliente vía Inertia shared prop.');
        $this->comment(' - VAPID_PRIVATE_KEY es secreta — en PDN vive en SSM Parameter Store.');
        $this->comment(' - Rotar invalida todas las subs existentes.');

        return self::SUCCESS;
    }

    /**
     * @return array{publicKey: string, privateKey: string}
     */
    private function generateWithOpensslCli(): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'vapid_');
        if ($tmp === false) {
            throw new RuntimeException('No pude crear archivo temporal.');
        }

        try {
            $status = 0;
            $output = [];
            exec('openssl ecparam -name prime256v1 -genkey -noout -out '.escapeshellarg($tmp).' 2>&1', $output, $status);
            if ($status !== 0) {
                throw new RuntimeException('openssl ecparam falló: '.implode("\n", $output));
            }

            $pem = file_get_contents($tmp);
            if ($pem === false) {
                throw new RuntimeException('No pude leer el PEM generado.');
            }

            $res = openssl_pkey_get_private($pem);
            if ($res === false) {
                throw new RuntimeException('openssl_pkey_get_private falló sobre el PEM.');
            }

            $details = openssl_pkey_get_details($res);
            if ($details === false || ! isset($details['ec'])) {
                throw new RuntimeException('openssl_pkey_get_details no devolvió componentes EC.');
            }

            $x = str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT);
            $y = str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
            $d = str_pad($details['ec']['d'], 32, "\0", STR_PAD_LEFT);

            return [
                'publicKey' => Base64Url::encode("\x04".$x.$y),
                'privateKey' => Base64Url::encode($d),
            ];
        } finally {
            @unlink($tmp);
        }
    }
}
