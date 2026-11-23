/**
 * Nöbet (/panel/nobet/:sekme; sunucu php/Http/NobetAdminController). Sekmeler:
 *
 * - Takvim: ay ay, satır gün, sütun bölge (eski sistemin Excel biçimi); CSV indir; nöbete tıklayınca gerekçesi ve
 *   (yetki varsa) aynı bölgeden başka eczaneye verme.
 * - Döngü: kurallar (seç, sırala), ek kurallar, önizle, kaydet; geçmiş döngüler ve silme.
 * - Eczaneler (kmGrid), Bölgeler ve gruplar, Özel günler: ekle / düzenle / sil.
 *
 * Okuma `km_view_nobet`, yazma `km_manage_nobet` ($store.session.can). Kaynak: derlenmiş hali panel/nobet.js
 * (npm run module:build); çatıya yalnızca panel SDK'sıyla bağlanır.
 */
import { confirmAction, fmt, href, http, localSource, replaceUrl, toast } from 'kodmob';

const YONETME = 'km_manage_nobet';
const API = '/admin/nobet';

const api = {
    kayitlar: () => http.get(`${API}/kayitlar`),
    takvim: (baslangic, bitis) => http.get(`${API}/takvim?baslangic=${baslangic}&bitis=${bitis}`),
    donguler: () => http.get(`${API}/donguler`),
    kaydet: (tur, id, body) => (id ? http.put(`${API}/${tur}/${id}`, body) : http.post(`${API}/${tur}`, body)),
    sil: (tur, id) => http.delete(`${API}/${tur}/${id}`),
    onizle: (body) => http.post(`${API}/donguler/onizle`, body),
    olustur: (body) => http.post(`${API}/donguler`, body),
    donguSil: (id) => http.delete(`${API}/donguler/${id}`),
    degistir: (id, eczaneId) => http.put(`${API}/nobetler/${id}`, { eczane_id: eczaneId }),
};

const SEKMELER = [
    { key: 'takvim', label: 'Takvim', icon: 'calendar' },
    { key: 'dongu', label: 'Döngü', icon: 'refresh' },
    { key: 'eczaneler', label: 'Eczaneler', icon: 'list' },
    { key: 'bolgeler', label: 'Bölgeler ve gruplar', icon: 'layers' },
    // Sekme anahtarı adresteki parçaya tanımın `tabs`'ıyla çevrilir (ozelGunler → /panel/nobet/ozel-gunler).
    { key: 'ozelGunler', label: 'Özel günler', icon: 'star' },
];

const GUNLER = ['Pazar', 'Pazartesi', 'Salı', 'Çarşamba', 'Perşembe', 'Cuma', 'Cumartesi'];
const AYLAR = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
export const VARDIYALAR = { tumgun: '', gunduz: 'Gündüz', gece: 'Gece' };
const KAYNAKLAR = { motor: 'Döngü', elle: 'Elle değiştirildi', aktarim: 'Eski sistemden' };

/**
 * Eski arayüzün kural sırası; seçili gelenler odaların en sık kullandığı kurallar. R ve S yeni: S eşitliği bozsun diye
 * en sonda ve seçili (200 kurada aralık ve gün adı dengesi biraz iyileşiyor); R seçilmemiş (öne konunca aralığı
 * düzeltiyor ama küçük bölgede gün adı dengesini bozuyor; oda seçer).
 */
const KURAL_SIRASI = ['O', 'L', 'K', 'G', 'H', 'I', 'J', 'A', 'R', 'M', 'N', 'B', 'C', 'D', 'E', 'P', 'S'];
const VARSAYILAN_KURALLAR = ['A', 'N', 'B', 'C', 'D', 'E', 'S'];

const DEVIR_GRUPLARI = [
    { label: 'Gün', keys: ['pazartesi', 'sali', 'carsamba', 'persembe', 'cuma', 'cumartesi', 'pazar'] },
    { label: 'Hafta', keys: ['haftaici', 'haftasonu'] },
    { label: 'Mevsim', keys: ['kis', 'ilkbahar', 'yaz', 'sonbahar'] },
    { label: 'Ay', keys: ['ocak', 'subat', 'mart', 'nisan', 'mayis', 'haziran', 'temmuz', 'agustos', 'eylul', 'ekim', 'kasim', 'aralik'] },
];
const DEVIR_ADLARI = {
    pazartesi: 'Pzt', sali: 'Sal', carsamba: 'Çar', persembe: 'Per', cuma: 'Cum', cumartesi: 'Cmt', pazar: 'Paz',
    haftaici: 'Hafta içi', haftasonu: 'Hafta sonu', kis: 'Kış', ilkbahar: 'İlkbahar', yaz: 'Yaz', sonbahar: 'Sonbahar',
    ocak: 'Oca', subat: 'Şub', mart: 'Mar', nisan: 'Nis', mayis: 'May', haziran: 'Haz', temmuz: 'Tem', agustos: 'Ağu',
    eylul: 'Eyl', ekim: 'Eki', kasim: 'Kas', aralik: 'Ara',
};

/**
 * İkon adresi. Derlenmiş panelde ikon seti ayrı dosyadadır (`<html data-icons>`); çatının şablonlarındaki `#i-…`
 * derlemede çevrilir ama çalışma anında yüklenen modül şablonu çevrilmez (panel SDK'sında da yardımcı yok).
 */
const IKONLAR = typeof document !== 'undefined' ? document.documentElement.dataset.icons || '' : '';
export const ikon = (ad) => `${IKONLAR}#i-${ad}`;

/** Y-m-d (yerel saat; toISOString UTC'ye kaydırır). */
export function ymd(d) {
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
}

/** "2027-01-04" → Date (yerel gece yarısı). */
function tarih(s) {
    const [y, m, d] = s.split('-').map(Number);
    return new Date(y, m - 1, d);
}

/** 1.01.2027 */
export function gunAyYil(s) {
    const d = tarih(s);
    return `${d.getDate()}.${String(d.getMonth() + 1).padStart(2, '0')}.${d.getFullYear()}`;
}

/**
 * Nöbetleri takvim satırlarına çevirir: aralığın her günü bir satır, her bölge bir hücre (vardiyasıyla birden çok nöbet).
 *
 * @returns {{ tarih: string, gun: string, haftasonu: boolean, ozel: string|null, hucreler: Record<number, object[]> }[]}
 */
export function satirlar(baslangic, bitis, nobetler, ozelGunler = []) {
    const ozel = Object.fromEntries(ozelGunler.map((g) => [g.tarih, g.ad]));
    const gunler = {};
    for (const n of nobetler) {
        ((gunler[n.tarih] ??= {})[n.bolge_id] ??= []).push(n);
    }
    const sira = { gunduz: 0, gece: 1, tumgun: 2 };
    const sonuc = [];
    for (let d = tarih(baslangic); d <= tarih(bitis); d.setDate(d.getDate() + 1)) {
        const t = ymd(d);
        const hucreler = gunler[t] || {};
        Object.values(hucreler).forEach((l) => l.sort((a, b) => sira[a.vardiya] - sira[b.vardiya]));
        sonuc.push({ tarih: t, gun: GUNLER[d.getDay()], haftasonu: d.getDay() === 0 || d.getDay() === 6, ozel: ozel[t] || null, hucreler });
    }
    return sonuc;
}

/** Takvimi CSV yapar (Excel Türkçe: noktalı virgül, UTF-8 BOM). */
export function csv(bolgeler, satirlar) {
    const kacis = (v) => (/[";\n]/.test(v) ? `"${v.replace(/"/g, '""')}"` : v);
    const baslik = ['Tarih', 'Gün', ...bolgeler.map((b) => b.ad)];
    const govde = satirlar.map((s) => [
        gunAyYil(s.tarih),
        s.gun + (s.ozel ? ` (${s.ozel})` : ''),
        ...bolgeler.map((b) => (s.hucreler[b.id] || []).map((n) => (VARDIYALAR[n.vardiya] ? `${VARDIYALAR[n.vardiya]}: ` : '') + (n.eczane || '?')).join(' / ')),
    ]);
    return '﻿' + [baslik, ...govde].map((r) => r.map((v) => kacis(String(v))).join(';')).join('\r\n');
}

/** Motorun gerekçesi okunur cümleler olarak. */
export function gerekceMetni(gerekce, kurallar) {
    if (!gerekce) return ['Gerekçe kaydı yok.'];
    if (gerekce.elle) return ['Nöbet panelden elle değiştirildi.'];
    const etiket = Object.fromEntries((kurallar || []).map((k) => [k.harf, k.etiket]));
    const satir = (gerekce.adimlar || []).map((a) => `${a.kural} · ${etiket[a.kural] || ''}: ${a.once} adaydan ${a.sonra} aday kaldı.`);
    if (!satir.length) satir.push('Hiçbir kural adayları daraltmadı.');
    satir.push(gerekce.kura > 1 ? `Kalan ${gerekce.kura} aday arasından kurayla seçildi.` : 'Tek aday kaldı.');
    return satir;
}

const bosEczane = () => ({ id: null, ad: '', eczaci: '', telefon: '', adres: '', bolge_id: '', grup_id: '', enlem: '', boylam: '', aktif: true, devir: {} });

export function kmNobet(route = {}) {
    return {
        fmt,
        ikon,
        sekmeler: SEKMELER,
        sekme: 'takvim',
        _onRoute: null,
        yukleniyor: true,
        mesgul: null,
        hatalar: {},
        bolgeler: [],
        gruplar: [],
        eczaneler: [],
        ozelGunler: [],
        kurallar: [],
        devirGruplari: DEVIR_GRUPLARI,
        devirAdlari: DEVIR_ADLARI,
        vardiyalar: VARDIYALAR,
        kaynaklar: KAYNAKLAR,

        // Takvim
        ay: ymd(new Date()).slice(0, 7),
        takvim: { bolgeler: [], satirlar: [] },
        takvimYukleniyor: false,
        secili: null,
        yeniEczane: '',

        // Döngü
        donguler: [],
        dongu: null,
        onizleme: null,

        // Pencereler (açık / kapalı ayrı; form nesnesi boşalmaz)
        eczaneAcik: false,
        eczane: bosEczane(),
        bolgeAcik: false,
        bolge: { id: null, ad: '', sira: 0 },
        grupAcik: false,
        grup: { id: null, ad: '', eslesme: [], yedek_id: '', ust_id: '' },
        ozelGun: { tarih: '', ad: '' },
        grid: null,

        async init() {
            this.dongu = this.bosDongu();
            this.goster(route.tab);
            this._onRoute = (e) => this.goster(e.detail?.tab);
            window.addEventListener('km-route', this._onRoute);
            this.grid = this.gridAyari();
            await this.yukle();
            await Promise.all([this.takvimYukle(), this.dongulerYukle()]);
        },

        destroy() {
            window.removeEventListener('km-route', this._onRoute);
        },

        get yonetir() {
            return window.Alpine.store('session').can(YONETME);
        },

        goster(key) {
            this.sekme = SEKMELER.some((s) => s.key === key) ? key : 'takvim';
            replaceUrl(href('nobet', { tab: this.sekme }));
        },

        async yukle() {
            try {
                const v = await api.kayitlar();
                Object.assign(this, { bolgeler: v.bolgeler, gruplar: v.gruplar, eczaneler: v.eczaneler, ozelGunler: v.ozel_gunler, kurallar: v.kurallar });
                window.dispatchEvent(new CustomEvent('grid-reload', { detail: { id: 'nobet-eczaneler' } }));
            } catch (error) {
                toast.error(error);
            } finally {
                this.yukleniyor = false;
            }
        },

        bolgeAdi(id) {
            return this.bolgeler.find((b) => b.id === Number(id))?.ad || '—';
        },
        grupAdi(id) {
            return this.gruplar.find((g) => g.id === Number(id))?.ad || '—';
        },
        eczaneAdi(id) {
            return this.eczaneler.find((e) => e.id === Number(id))?.ad || '—';
        },

        // ------------------------------------------------------------ takvim

        get ayAdi() {
            const [y, m] = this.ay.split('-').map(Number);
            return `${AYLAR[m - 1]} ${y}`;
        },

        ayKaydir(n) {
            const [y, m] = this.ay.split('-').map(Number);
            this.ay = ymd(new Date(y, m - 1 + n, 1)).slice(0, 7);
            this.takvimYukle();
        },

        async takvimYukle() {
            const [y, m] = this.ay.split('-').map(Number);
            const bas = ymd(new Date(y, m - 1, 1));
            const bit = ymd(new Date(y, m, 0));
            this.takvimYukleniyor = true;
            try {
                const v = await api.takvim(bas, bit);
                this.takvim = { bolgeler: v.bolgeler, satirlar: satirlar(bas, bit, v.nobetler, v.ozel_gunler), bos: !v.nobetler.length };
            } catch (error) {
                toast.error(error);
            } finally {
                this.takvimYukleniyor = false;
            }
        },

        csvIndir() {
            const blob = new Blob([csv(this.takvim.bolgeler, this.takvim.satirlar)], { type: 'text/csv;charset=utf-8' });
            const a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = `nobet-${this.ay}.csv`;
            a.click();
            URL.revokeObjectURL(a.href);
        },

        nobetAc(n) {
            this.secili = n;
            this.yeniEczane = '';
        },

        get adaylar() {
            if (!this.secili) return [];
            return this.eczaneler.filter((e) => e.bolge_id === this.secili.bolge_id && e.id !== this.secili.eczane_id);
        },

        gerekce(n) {
            return gerekceMetni(n?.gerekce, this.kurallar);
        },

        async degistir() {
            if (!this.yeniEczane || this.mesgul) return;
            this.mesgul = 'degistir';
            try {
                const v = await api.degistir(this.secili.id, Number(this.yeniEczane));
                toast.success(`${gunAyYil(this.secili.tarih)} nöbeti ${v.yeni} eczanesine verildi.`);
                this.secili = null;
                await this.takvimYukle();
            } catch (error) {
                toast.error(error);
            } finally {
                this.mesgul = null;
            }
        },

        // ------------------------------------------------------------ döngü

        bosDongu() {
            const yarin = new Date();
            yarin.setDate(yarin.getDate() + 1);
            return {
                baslangic: ymd(yarin), gun: 90, tohum: '', aralik: 7, sayacBaslangic: '',
                sira: [...KURAL_SIRASI], secili: [...VARSAYILAN_KURALLAR],
                ikili: [], tarihEngelleri: [], grupEngelleri: [], gunduz: {},
            };
        },

        kural(harf) {
            return this.kurallar.find((k) => k.harf === harf) || { harf, etiket: harf };
        },

        kuralSec(harf) {
            const s = this.dongu.secili;
            this.dongu.secili = s.includes(harf) ? s.filter((h) => h !== harf) : [...s, harf];
            this.onizleme = null;
        },

        kuralTasi(i, yon) {
            const sira = [...this.dongu.sira];
            const j = i + yon;
            if (j < 0 || j >= sira.length) return;
            [sira[i], sira[j]] = [sira[j], sira[i]];
            this.dongu.sira = sira;
            this.onizleme = null;
        },

        donguGirdisi() {
            const d = this.dongu;
            return {
                baslangic: d.baslangic,
                gun: Number(d.gun),
                kurallar: d.sira.filter((h) => d.secili.includes(h)).join(','),
                ikili_engeller: d.ikili.filter((r) => r[0] && r[1]).map((r) => [Number(r[0]), Number(r[1])]),
                grup_engelleri: d.grupEngelleri.filter((r) => r[0] && r[1]).map((r) => [Number(r[0]), Number(r[1])]),
                tarih_engelleri: d.tarihEngelleri.filter((r) => r.eczane).map((r) => ({ eczane: Number(r.eczane), baslangic: r.baslangic, bitis: r.bitis })),
                cumartesi_gunduz: d.secili.includes('K') ? Object.fromEntries(Object.entries(d.gunduz).filter(([, v]) => v !== '' && v !== null)) : {},
                tohum: d.tohum || null,
                en_az_aralik: d.secili.includes('R') ? Number(d.aralik) || 7 : 7,
                sayac_baslangic: d.sayacBaslangic || null,
            };
        },

        async onizle() {
            if (this.mesgul) return;
            this.mesgul = 'onizle';
            this.hatalar = {};
            try {
                const v = await api.onizle(this.donguGirdisi());
                const son = v.atamalar.length ? v.atamalar[v.atamalar.length - 1].tarih : this.dongu.baslangic;
                const nobetler = v.atamalar.map((a) => ({ ...a, bolge_id: a.bolge, eczane_id: a.eczane, eczane: this.eczaneAdi(a.eczane) }));
                this.onizleme = { ...v, satirlar: satirlar(this.dongu.baslangic, son, nobetler).slice(0, 62) };
                // Kaydedilen takvim önizlenenle aynı olsun: aynı tohum.
                this.dongu.tohum = v.tohum;
            } catch (error) {
                this.hatalar = error?.details?.fields || {};
                toast.error(error);
            } finally {
                this.mesgul = null;
            }
        },

        async olustur() {
            if (this.mesgul) return;
            const ok = await confirmAction({
                title: 'Döngü kaydedilsin mi?',
                message: `${gunAyYil(this.dongu.baslangic)} başlangıçlı ${this.dongu.gun} günlük nöbet yazılır. Nöbeti önceden yazılmış günler atlanır.`,
                confirmText: 'Kaydet',
            });
            if (!ok) return;
            this.mesgul = 'olustur';
            this.hatalar = {};
            try {
                const v = await api.olustur(this.donguGirdisi());
                toast.success(`${v.dongu.ad} kaydedildi: ${v.atama_sayisi} nöbet.`);
                this.onizleme = null;
                this.dongu.tohum = '';
                this.ay = v.dongu.baslangic.slice(0, 7);
                await Promise.all([this.dongulerYukle(), this.takvimYukle()]);
            } catch (error) {
                this.hatalar = error?.details?.fields || {};
                toast.error(error);
            } finally {
                this.mesgul = null;
            }
        },

        async dongulerYukle() {
            try {
                this.donguler = (await api.donguler()).items;
            } catch (error) {
                toast.error(error);
            }
        },

        async donguSil(d) {
            const ok = await confirmAction({
                title: `${d.ad} silinsin mi?`,
                message: `Döngünün yazdığı ${d.atama_sayisi} nöbet (elle değiştirilenler dahil) silinir. Geri alınamaz.`,
                confirmText: 'Sil', tone: 'danger',
            });
            if (!ok) return;
            try {
                const v = await api.donguSil(d.id);
                toast.success(`Döngü silindi (${v.silinen_nobet} nöbet).`);
                await Promise.all([this.dongulerYukle(), this.takvimYukle()]);
            } catch (error) {
                toast.error(error);
            }
        },

        // ------------------------------------------------------------ eczaneler

        gridAyari() {
            return {
                id: 'nobet-eczaneler',
                source: async (q) => localSource(this.eczaneler.map((e) => ({ ...e, durum: e.aktif ? 'Etkin' : 'Pasif' })), { search: ['ad', 'eczaci', 'telefon', 'adres'], facets: ['bolge', 'durum'] })(q),
                pageSize: 50,
                sort: { key: 'ad', order: 'asc' },
                exportName: 'eczaneler',
                empty: { title: 'Eczane yok', text: 'Önce Bölgeler ve gruplar sekmesinde bölge ekleyin, sonra eczaneleri.', icon: 'list' },
                columns: [
                    { key: 'ad', label: 'Eczane', sortable: true, strong: true, card: 'title' },
                    { key: 'eczaci', label: 'Eczacı', cell: (r) => r.eczaci || '—', card: 'subtitle', hideBelow: 'md' },
                    { key: 'telefon', label: 'Telefon', cell: (r) => r.telefon || '—', hideBelow: 'lg', card: 'spec' },
                    { key: 'bolge', label: 'Bölge', sortable: true, card: 'spec' },
                    { key: 'grup', label: 'Grup', cell: (r) => r.grup || '—', sortable: true, hideBelow: 'md', card: 'spec' },
                    { key: 'durum', label: 'Durum', card: 'meta' },
                ],
                filters: [
                    { key: 'q', type: 'search', placeholder: 'Ad, eczacı, telefon ya da adres' },
                    { key: 'bolge', type: 'select', label: 'Bölge' },
                    { key: 'durum', type: 'select', label: 'Durum' },
                ],
                rowActions: [
                    { key: 'duzenle', label: 'Düzenle', icon: 'pencil', can: YONETME },
                    { key: 'sil', label: 'Sil', icon: 'trash', danger: true, can: YONETME },
                ],
            };
        },

        async eczaneIslem({ action, row }) {
            if (action === 'duzenle') return this.eczaneAc(row);
            if (action !== 'sil') return;
            const ok = await confirmAction({ title: `${row.ad} silinsin mi?`, message: 'Nöbet kaydı olan eczane silinmez; pasif yapın (döngülere girmez, geçmişi kalır).', confirmText: 'Sil', tone: 'danger' });
            if (!ok) return;
            await this.sil('eczaneler', row.id, 'Eczane silindi.');
        },

        eczaneAc(e = null) {
            this.hatalar = {};
            this.eczane = e
                ? { ...bosEczane(), ...e, grup_id: e.grup_id ?? '', enlem: e.enlem ?? '', boylam: e.boylam ?? '', devir: { ...e.devir } }
                : { ...bosEczane(), bolge_id: this.bolgeler[0]?.id ?? '' };
            this.eczaneAcik = true;
        },

        async eczaneKaydet() {
            const e = this.eczane;
            const devir = Object.fromEntries(Object.entries(e.devir).filter(([, v]) => v !== '' && v !== null).map(([k, v]) => [k, Number(v)]));
            const body = { ...e, enlem: String(e.enlem ?? ''), boylam: String(e.boylam ?? ''), devir };
            if (await this.kaydet('eczaneler', e.id, body, 'Eczane kaydedildi.')) this.eczaneAcik = false;
        },

        // ------------------------------------------------------------ bölgeler, gruplar, özel günler

        bolgeAc(b = null) {
            this.hatalar = {};
            this.bolge = b ? { id: b.id, ad: b.ad, sira: b.sira } : { id: null, ad: '', sira: this.bolgeler.length + 1 };
            this.bolgeAcik = true;
        },

        async bolgeKaydet() {
            if (await this.kaydet('bolgeler', this.bolge.id, this.bolge, 'Bölge kaydedildi.')) this.bolgeAcik = false;
        },

        grupAc(g = null) {
            this.hatalar = {};
            this.grup = g ? { id: g.id, ad: g.ad, eslesme: [...g.eslesme], yedek_id: g.yedek_id ?? '', ust_id: g.ust_id ?? '' } : { id: null, ad: '', eslesme: [], yedek_id: '', ust_id: '' };
            this.grupAcik = true;
        },

        grupEslesme(id) {
            const l = this.grup.eslesme;
            this.grup.eslesme = l.includes(id) ? l.filter((x) => x !== id) : [...l, id];
        },

        async grupKaydet() {
            if (await this.kaydet('gruplar', this.grup.id, this.grup, 'Grup kaydedildi.')) this.grupAcik = false;
        },

        async ozelGunEkle() {
            if (await this.kaydet('ozel-gunler', null, this.ozelGun, 'Özel gün eklendi.')) this.ozelGun = { tarih: '', ad: '' };
        },

        async kayitSil(tur, kayit, ad) {
            const ok = await confirmAction({ title: `${ad} silinsin mi?`, confirmText: 'Sil', tone: 'danger' });
            if (ok) await this.sil(tur, kayit.id, 'Silindi.');
        },

        async kaydet(tur, id, body, mesaj) {
            if (this.mesgul) return false;
            this.mesgul = tur;
            this.hatalar = {};
            try {
                await api.kaydet(tur, id, body);
                toast.success(mesaj);
                await this.yukle();
                return true;
            } catch (error) {
                this.hatalar = error?.details?.fields || {};
                toast.error(error);
                return false;
            } finally {
                this.mesgul = null;
            }
        },

        async sil(tur, id, mesaj) {
            try {
                await api.sil(tur, id);
                toast.success(mesaj);
                await this.yukle();
            } catch (error) {
                toast.error(error);
            }
        },

        gunAyYil,
    };
}
