import { component, http } from 'kodmob';
import { MAZERET_DURUMU, formDurumu, kisaZaman, takasDurumu, uzunTarih } from './hesap-ortak.js';

/**
 * Hesabım › Oda yönetimi (hesap-oda.njk; uç GET /members/nobet/oda): odanın kurumundaki yöneticiye eczacıların
 * mazeret ve takas talepleri (karar), eczane ↔ hesap bağı. Kişileri kuruma davet etmek Kurum üyeleri sayfasında;
 * döngü ve eczane kayıtları yönetim arayüzünde.
 */
function nobetOda() {
    return {
        ...formDurumu(),
        yuklendi: false,
        mazeretler: [],
        takaslar: [],
        eczaneler: [],
        uyeler: [],
        takasOnayi: true,
        notlar: {},
        sekme: 'talepler',
        ara: '',
        uzunTarih,
        kisaZaman,

        init() {
            this.yukle();
        },

        async yukle() {
            try {
                this.al(await http.get('/members/nobet/oda'));
            } catch (error) {
                this.error = error.message;
            }
            this.yuklendi = true;
        },

        al(res) {
            if (!res) return;
            this.mazeretler = res.mazeretler || [];
            this.takaslar = res.takaslar || [];
            this.eczaneler = res.eczaneler || [];
            this.uyeler = res.uyeler || [];
            this.takasOnayi = res.takas_onayi !== false;
        },

        get bekleyenMazeret() {
            return this.mazeretler.filter((m) => m.durum === 'talep');
        },

        get bekleyenTakas() {
            return this.takaslar.filter((t) => t.durum === 'kabul');
        },

        get sonuclanan() {
            return [
                ...this.mazeretler.filter((m) => m.durum !== 'talep').map((m) => ({ ...m, tip: 'mazeret' })),
                ...this.takaslar.filter((t) => t.durum !== 'kabul').map((t) => ({ ...t, tip: 'takas' })),
            ].sort((a, b) => (b.karar_tarihi || b.eklenme || '').localeCompare(a.karar_tarihi || a.eklenme || '')).slice(0, 30);
        },

        get bagsiz() {
            return this.eczaneler.filter((e) => e.aktif && !e.uye_id).length;
        },

        get liste() {
            const q = this.ara.trim().toLocaleLowerCase('tr');
            return q ? this.eczaneler.filter((e) => `${e.ad} ${e.bolge} ${e.uye || ''}`.toLocaleLowerCase('tr').includes(q)) : this.eczaneler;
        },

        mazeretDurumu(m) {
            return MAZERET_DURUMU[m.durum] || [m.durum, 'badge-neutral'];
        },

        takasDurumu(t) {
            return takasDurumu(t, this.takasOnayi);
        },

        async mazeretKarari(m, karar) {
            const res = await this.run(() => http.post(`/members/nobet/oda/mazeret/${m.id}/karar`, { karar, not: this.notlar[`m${m.id}`] || '' }),
                karar === 'onay' ? 'Mazeret onaylandı; eczacıya bildirildi. Nöbet değişikliğini yönetim arayüzünün Nöbet Kayıtları sayfasından yapın.' : 'Mazeret reddedildi; eczacıya bildirildi.');
            this.al(res);
        },

        async takasKarari(t, karar) {
            if (karar === 'onay' && !window.confirm(`${t.eczane} (${uzunTarih(t.tarih)}) ile ${t.karsi_eczane} (${uzunTarih(t.karsi_tarih)}) nöbetleri yer değiştirsin mi?`)) return;
            const res = await this.run(() => http.post(`/members/nobet/oda/takas/${t.id}/karar`, { karar, not: this.notlar[`t${t.id}`] || '' }),
                karar === 'onay' ? 'Takas uygulandı; iki eczaneye bildirildi.' : 'Takas reddedildi; iki eczaneye bildirildi.');
            if (res) this.al(res);
            else await this.yukle();
        },

        async bagla(e, uyeId) {
            const res = await this.run(() => http.put(`/members/nobet/oda/eczaneler/${e.id}/uye`, { uye_id: uyeId ? Number(uyeId) : null }), 'Kaydedildi.');
            if (res) this.al(res);
            else await this.yukle();
        },
    };
}

component('nobetOda', nobetOda);
