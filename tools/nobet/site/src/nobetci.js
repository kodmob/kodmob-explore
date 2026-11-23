import { component, http, lang } from 'kodmob';

/**
 * Nöbetçi Eczaneler sayfası (nobetci.njk; derlenmiş hali site/nobetci.js, site SDK'sıyla). Veri herkese açık uçtan
 * (`GET /nobet/nobetciler`): sayfa önbellekte kalsa da nöbetçi her açılışta güncel. "Bugün" sunucunun devir saatine göre
 * (gece 02:00'de dünün nöbetçisi).
 */
const DIL = {
    tr: {
        gunler: ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'],
        aylar: ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'],
        vardiya: { tumgun: '', gunduz: 'Gündüz', gece: 'Gece' },
        eczane: (ad) => `${ad} Eczanesi`,
    },
    en: {
        gunler: ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        aylar: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
        vardiya: { tumgun: '', gunduz: 'Day shift', gece: 'Night shift' },
        eczane: (ad) => `${ad} Pharmacy`,
    },
};
const dilOf = (kod) => DIL[kod] || DIL.tr;

function tarih(s) {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
}

/** "9 Kasım Pazartesi" (İngilizcede "Monday 9 November") */
export function uzunTarih(s, kod = 'tr') {
    const d = tarih(s);
    const m = dilOf(kod);
    return kod === 'en' ? `${m.gunler[d.getDay()]} ${d.getDate()} ${m.aylar[d.getMonth()]}` : `${d.getDate()} ${m.aylar[d.getMonth()]} ${m.gunler[d.getDay()]}`;
}

/** Arama bağlantısı: yalnızca rakam ve + (0312 555 01 01 → tel:03125550101). */
export function telBaglantisi(tel) {
    const temiz = String(tel || '').replace(/[^0-9+]/g, '');
    return temiz ? `tel:${temiz}` : '';
}

/** Harita: konum varsa koordinat, yoksa adres ve eczane adıyla arama. */
export function haritaBaglantisi(n) {
    const q = n.enlem !== null && n.boylam !== null ? `${n.enlem},${n.boylam}` : [n.eczane, 'Eczanesi', n.adres].filter(Boolean).join(' ');
    return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(q)}`;
}

component('nobetciSayfa', () => ({
    durum: 'yukleniyor',
    bugun: null,
    sonraki: [],
    bolgeler: [],
    devirSaati: '08:30',
    vardiya: dilOf(lang()).vardiya,
    uzunTarih: (s) => uzunTarih(s, lang()),
    eczaneAdi: (ad) => dilOf(lang()).eczane(ad),
    telBaglantisi,
    haritaBaglantisi,

    async init() {
        try {
            const v = await http.get('/nobet/nobetciler?gun=7');
            this.bolgeler = v.bolgeler;
            this.devirSaati = v.devir_saati;
            [this.bugun, ...this.sonraki] = v.gunler;
            this.durum = 'hazir';
        } catch {
            this.durum = 'hata';
        }
    },

    bolgeAdi(id) {
        return this.bolgeler.find((b) => b.id === id)?.ad || '';
    },

    /** Tek bölgeli odada bölge adı gösterilmez. */
    get cokBolge() {
        return this.bolgeler.length > 1;
    },
}));
