import { component, http } from 'kodmob';
import { MAZERET_DURUMU, VARDIYA, formDurumu, kisaZaman, takasDurumu, uzunTarih } from './hesap-ortak.js';

/**
 * Hesabım › Nöbetlerim (hesap-nobetlerim.njk; uç GET /members/nobet): eczacının eczanesi, gelecek nöbetleri, mazeret
 * bildirme, nöbet takası (teklif, gelen teklife yanıt, geri alma). Kurallar sunucuda.
 */
function nobetlerim() {
    return {
        ...formDurumu(),
        yuklendi: false,
        eczane: null,
        nobetler: [],
        mazeretler: [],
        takaslar: [],
        gecmis: 0,
        takasOnayi: true,
        odaYoneticisi: false,
        mazeret: { tarih: '', aciklama: '' },
        // Takas formu: hangi nöbet, adaylar, seçilen.
        takas: null,
        VARDIYA,
        uzunTarih,
        kisaZaman,
        takasDurumu,

        init() {
            this.yukle();
        },

        async yukle() {
            try {
                this.al(await http.get('/members/nobet'));
            } catch (error) {
                this.error = error.message;
            }
            this.yuklendi = true;
        },

        al(res) {
            this.eczane = res.eczane;
            this.nobetler = res.nobetler || [];
            this.mazeretler = res.mazeretler || [];
            this.takaslar = res.takaslar || [];
            this.gecmis = res.gecmis_nobet || 0;
            this.takasOnayi = res.takas_onayi !== false;
            this.odaYoneticisi = !!res.oda;
        },

        get gelenTeklifler() {
            return this.takaslar.filter((t) => t.yon === 'gelen' && t.durum === 'teklif');
        },

        /** Açık takastaki nöbetin günü (rozet ve düğme için). */
        acikTakas(n) {
            return this.takaslar.find((t) => ['teklif', 'kabul'].includes(t.durum) && (t.yon === 'giden' ? t.tarih : t.karsi_tarih) === n.tarih) || null;
        },

        mazeretDurumu(m) {
            return MAZERET_DURUMU[m.durum] || [m.durum, 'badge-neutral'];
        },

        mazeretGunu(n) {
            this.mazeret.tarih = n.tarih;
            this.$nextTick(() => document.querySelector('[data-nb="mazeret-aciklama"]')?.focus());
        },

        async mazeretGonder() {
            const res = await this.run(() => http.post('/members/nobet/mazeret', this.mazeret), 'Mazeretiniz oda yönetimine gönderildi.');
            if (res) {
                this.mazeret = { tarih: '', aciklama: '' };
                await this.yukle();
            }
        },

        async mazeretGeriAl(m) {
            if (!window.confirm(`${uzunTarih(m.tarih)} için mazeret talebiniz geri alınsın mı?`)) return;
            if (await this.run(() => http.delete(`/members/nobet/mazeret/${m.id}`), 'Talep geri alındı.')) await this.yukle();
        },

        async takasAc(n) {
            this.takas = { nobet: n, adaylar: [], secili: '', aciklama: '', yukleniyor: true };
            const res = await this.run(() => http.get(`/members/nobet/takas-adaylari?nobet=${n.id}`));
            if (this.takas) {
                this.takas.adaylar = res?.adaylar || [];
                this.takas.yukleniyor = false;
            }
        },

        async takasGonder() {
            const t = this.takas;
            const res = await this.run(
                () => http.post('/members/nobet/takas', { nobet_id: t.nobet.id, karsi_nobet_id: Number(t.secili), aciklama: t.aciklama }),
                'Takas teklifiniz karşı eczaneye gönderildi.',
            );
            if (res) {
                this.takas = null;
                await this.yukle();
            }
        },

        async yanit(t, yanit) {
            if (yanit === 'kabul' && !window.confirm(`${uzunTarih(t.karsi_tarih)} nöbetiniz ${t.eczane} eczanesinin ${uzunTarih(t.tarih)} nöbetiyle değişsin mi?`)) return;
            const res = await this.run(() => http.post(`/members/nobet/takas/${t.id}/yanit`, { yanit }));
            if (!res) return;
            this.message = res.durum === 'onay' ? 'Takas uygulandı; nöbetleriniz güncellendi.' : res.durum === 'kabul' ? 'Kabul ettiniz; takas oda yönetiminin onayından sonra uygulanır.' : 'Teklifi reddettiniz.';
            await this.yukle();
        },

        async takasIptal(t) {
            if (!window.confirm('Takas teklifiniz geri alınsın mı?')) return;
            if (await this.run(() => http.delete(`/members/nobet/takas/${t.id}`), 'Teklif geri alındı.')) await this.yukle();
        },
    };
}

component('nobetlerim', nobetlerim);
