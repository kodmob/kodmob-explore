import { fieldErrors } from 'kodmob';

/**
 * Hesabım'daki nöbet sayfalarının ortakları (hesap-nobetlerim.js, hesap-oda.js): tarih yazımı, durum adları, form
 * durumu. Kurallar sunucuda (Veri\Eczacim, Veri\Takaslar); buradaki yalnızca gösterim.
 */
const GUNLER = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
const AYLAR = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

export const VARDIYA = { tumgun: '', gunduz: 'Gündüz', gece: 'Gece' };

/** "12 Aralık 2026 Cumartesi" (yıl bu yılsa yazılmaz). */
export function uzunTarih(s, bugun = new Date()) {
    if (!s) return '';
    const [y, m, d] = s.slice(0, 10).split('-').map(Number);
    const t = new Date(y, m - 1, d);
    return `${d} ${AYLAR[m - 1]}${y !== bugun.getFullYear() ? ` ${y}` : ''} ${GUNLER[t.getDay()]}`;
}

/** "12.12.2026 14:30" (sunucunun "2026-12-12 14:30"u). */
export function kisaZaman(s) {
    if (!s) return '';
    const [gun, saat = ''] = s.split(' ');
    const [y, m, d] = gun.split('-');
    return `${d}.${m}.${y}${saat ? ` ${saat.slice(0, 5)}` : ''}`;
}

export const MAZERET_DURUMU = { talep: ['Bekliyor', 'badge-warning'], onay: ['Onaylandı', 'badge-success'], ret: ['Reddedildi', 'badge-danger'] };

/** Takasın durumu, bakanın tarafına göre (`yon`: giden | gelen; oda yönetiminde boş). @return [ad, rozet] */
export function takasDurumu(t, onayGerekir = true) {
    switch (t.durum) {
        case 'teklif':
            if (t.yon === 'giden') return ['Karşı eczanenin yanıtı bekleniyor', 'badge-warning'];
            if (t.yon === 'gelen') return ['Yanıtınız bekleniyor', 'badge-warning'];
            return ['Karşı eczanenin yanıtı bekleniyor', 'badge-neutral'];
        case 'kabul':
            return [onayGerekir ? 'Oda onayı bekleniyor' : 'Kabul edildi', 'badge-warning'];
        case 'onay':
            return ['Uygulandı', 'badge-success'];
        case 'ret':
            if (t.ret_eden === 'yonetim') return ['Oda yönetimi reddetti', 'badge-danger'];
            return [t.yon === 'gelen' ? 'Reddettiniz' : 'Karşı eczane reddetti', 'badge-danger'];
        default:
            return ['Geri alındı ya da geçersiz', 'badge-neutral'];
    }
}

/** Ortak form durumu: `busy`, alan hataları, ileti; `run()` isteği çalıştırır (422'de alan hataları). */
export function formDurumu() {
    return {
        busy: false,
        errors: {},
        message: '',
        error: '',

        async run(fn, basari = '') {
            this.busy = true;
            this.errors = {};
            this.message = '';
            this.error = '';
            try {
                const res = await fn();
                if (basari) this.message = basari;
                return res;
            } catch (error) {
                this.errors = fieldErrors(error);
                if (!Object.keys(this.errors).length) this.error = error.message;
                if (error?.status === 401) this.$store.account?.refresh();
                return null;
            } finally {
                this.busy = false;
            }
        },
    };
}
