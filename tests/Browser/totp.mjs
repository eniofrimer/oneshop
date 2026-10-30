import { createHmac } from 'node:crypto';

// Independent RFC 6238 generator for ephemeral browser fixtures only.
// https://www.rfc-editor.org/rfc/rfc6238.html
export function totp(secret, milliseconds = Date.now()) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bytes = [];
    let bits = 0, value = 0;
    for (const character of secret.toUpperCase().replace(/=+$/, '')) {
        const digit = alphabet.indexOf(character);
        if (digit < 0) throw new Error('Invalid browser fixture authenticator key.');
        value = (value << 5) | digit;
        bits += 5;
        if (bits >= 8) {
            bits -= 8;
            bytes.push((value >>> bits) & 255);
            value &= (1 << bits) - 1;
        }
    }
    const counter = Math.floor(milliseconds / 30000);
    const message = Buffer.alloc(8);
    message.writeBigUInt64BE(BigInt(counter));
    const digest = createHmac('sha1', Buffer.from(bytes)).update(message).digest();
    const offset = digest[digest.length - 1] & 15;
    const number = (digest.readUInt32BE(offset) & 0x7fffffff) % 1000000;
    return { code: String(number).padStart(6, '0'), counter };
}

export function invalidTotp(secret) {
    const valid = new Set([-1, 0, 1].map(window => totp(secret, Date.now() + window * 30000).code));
    for (let candidate = 0; candidate < 4; candidate++) {
        const code = String(candidate).padStart(6, '0');
        if (!valid.has(code)) return code;
    }
    throw new Error('No invalid browser fixture code available.');
}
