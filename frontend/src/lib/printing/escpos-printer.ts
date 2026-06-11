// Tipos mínimos de WebUSB. Evitamos depender de @types/w3c-web-usb para no
// agregar una dependencia solo por este componente. La superficie aquí cubre
// únicamente lo que el cliente necesita.
type USBEndpoint = { direction: 'in' | 'out'; endpointNumber: number };
type USBAlternateInterface = { endpoints: USBEndpoint[] };
type USBInterface = { interfaceNumber: number; alternates: USBAlternateInterface[] };
type USBConfiguration = { interfaces: USBInterface[] };
type USBDevice = {
    configuration: USBConfiguration | null;
    configurations: USBConfiguration[];
    open(): Promise<void>;
    close(): Promise<void>;
    selectConfiguration(value: number): Promise<void>;
    claimInterface(value: number): Promise<void>;
    transferOut(endpointNumber: number, data: ArrayBuffer): Promise<unknown>;
};
type USBFilter = { vendorId?: number; productId?: number };
type USB = {
    requestDevice(opts: { filters: USBFilter[] }): Promise<USBDevice>;
    getDevices(): Promise<USBDevice[]>;
};

declare global {
    interface Navigator {
        usb?: USB;
    }
}

/**
 * Cliente WebUSB para impresoras térmicas ESC/POS.
 *
 * El backend genera el binario ESC/POS; este módulo solo lo transporta a la
 * impresora seleccionada por el usuario. Si WebUSB no está disponible (Firefox,
 * Safari, contexto inseguro), devolvemos `supported: false` y el botón debe
 * caer al fallback de descarga del .bin.
 *
 * El handle USB no se persiste: por seguridad de WebUSB, el browser pide
 * permiso una vez por sesión y origin. Para evitar pedirlo cada vez, podríamos
 * usar `navigator.usb.getDevices()` en el siguiente reload (devuelve los
 * dispositivos previamente autorizados por este origin).
 */

const KNOWN_VENDORS = [
    { vendorId: 0x04b8 }, // Epson
    { vendorId: 0x0519 }, // Star Micronics
    { vendorId: 0x0483 }, // Xprinter / STM
    { vendorId: 0x0fe6 }, // SNBC / Bixolon clones
    { vendorId: 0x1a86 }, // QL-CH9102 (Xprinter USB)
];

export function isWebUsbSupported(): boolean {
    return typeof navigator !== 'undefined' && 'usb' in navigator;
}

async function findOutEndpoint(device: USBDevice): Promise<{ interfaceNumber: number; endpointNumber: number } | null> {
    for (const config of device.configurations) {
        for (const iface of config.interfaces) {
            for (const alt of iface.alternates) {
                const out = alt.endpoints.find((e) => e.direction === 'out');
                if (out) {
                    return { interfaceNumber: iface.interfaceNumber, endpointNumber: out.endpointNumber };
                }
            }
        }
    }
    return null;
}

export async function pickThermalPrinter(): Promise<USBDevice> {
    if (!isWebUsbSupported()) {
        throw new Error('WebUSB no es soportado por este navegador.');
    }
    return await navigator.usb!.requestDevice({ filters: KNOWN_VENDORS });
}

export async function sendBinaryToPrinter(device: USBDevice, data: ArrayBuffer): Promise<void> {
    await device.open();
    try {
        if (!device.configuration) {
            await device.selectConfiguration(1);
        }

        const out = await findOutEndpoint(device);
        if (!out) {
            throw new Error('No se encontró un endpoint OUT en la impresora.');
        }

        await device.claimInterface(out.interfaceNumber);
        await device.transferOut(out.endpointNumber, data);
    } finally {
        try {
            await device.close();
        } catch {
            /* ignore close errors */
        }
    }
}

export function downloadBinary(filename: string, data: ArrayBuffer): void {
    const blob = new Blob([data], { type: 'application/octet-stream' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}
