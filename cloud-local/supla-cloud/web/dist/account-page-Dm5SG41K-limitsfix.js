import {
    a as G
} from "./_commonjsHelpers-DsqdWQfm.js";
import {
    _ as P,
    c as y,
    o as m,
    bs as q,
    x as M,
    D as T,
    r as _,
    a as v,
    m as V,
    ao as z,
    I as O,
    s as I,
    h as S,
    g as E,
    b as i,
    i as b,
    t as c,
    A as K,
    F as H,
    k as U,
    n as C,
    w as N,
    S as B,
    B as j,
    U as D,
    G as Y,
    v as J,
    Z as W,
    q as x,
    a3 as Z,
    d as X,
    L as $,
    p as Q,
    T as ee
} from "./index-DfO4cloU-limitsfix.js";
import {
    S as te
} from "./select-for-subjects-DILS6c01.js";
import {
    A as ie
} from "./access-ids-dropdown-DrqCao3a.js";
import {
    w as ae
} from "./filters-DmwtiM_k.js";
var L = {
    exports: {}
};
var F;

function ne() {
    return F || (F = 1, (function(a, s) {
        (function() {
            function f(e) {
                if (typeof e > "u") throw new Error('Pathformer [constructor]: "element" parameter is required');
                if (e.constructor === String && (e = document.getElementById(e), !e)) throw new Error('Pathformer [constructor]: "element" parameter is not related to an existing ID');
                if (e instanceof window.SVGElement || e instanceof window.SVGGElement || /^svg$/i.test(e.nodeName)) this.el = e;
                else throw new Error('Pathformer [constructor]: "element" parameter must be a string or a SVGelement');
                this.scan(e)
            }
            f.prototype.TYPES = ["line", "ellipse", "circle", "polygon", "polyline", "rect"], f.prototype.ATTR_WATCH = ["cx", "cy", "points", "r", "rx", "ry", "x", "x1", "x2", "y", "y1", "y2"], f.prototype.scan = function(e) {
                for (var n, t, o, l, d = e.querySelectorAll(this.TYPES.join(",")), p = 0; p < d.length; p++) t = d[p], n = this[t.tagName.toLowerCase() + "ToPath"], o = n(this.parseAttr(t.attributes)), l = this.pathMaker(t, o), t.parentNode.replaceChild(l, t)
            }, f.prototype.lineToPath = function(e) {
                var n = {},
                    t = e.x1 || 0,
                    o = e.y1 || 0,
                    l = e.x2 || 0,
                    d = e.y2 || 0;
                return n.d = "M" + t + "," + o + "L" + l + "," + d, n
            }, f.prototype.rectToPath = function(e) {
                var n = {},
                    t = parseFloat(e.x) || 0,
                    o = parseFloat(e.y) || 0,
                    l = parseFloat(e.width) || 0,
                    d = parseFloat(e.height) || 0;
                if (e.rx || e.ry) {
                    var p = parseInt(e.rx, 10) || -1,
                        g = parseInt(e.ry, 10) || -1;
                    p = Math.min(Math.max(p < 0 ? g : p, 0), l / 2), g = Math.min(Math.max(g < 0 ? p : g, 0), d / 2), n.d = "M " + (t + p) + "," + o + " L " + (t + l - p) + "," + o + " A " + p + "," + g + ",0,0,1," + (t + l) + "," + (o + g) + " L " + (t + l) + "," + (o + d - g) + " A " + p + "," + g + ",0,0,1," + (t + l - p) + "," + (o + d) + " L " + (t + p) + "," + (o + d) + " A " + p + "," + g + ",0,0,1," + t + "," + (o + d - g) + " L " + t + "," + (o + g) + " A " + p + "," + g + ",0,0,1," + (t + p) + "," + o
                } else n.d = "M" + t + " " + o + " L" + (t + l) + " " + o + " L" + (t + l) + " " + (o + d) + " L" + t + " " + (o + d) + " Z";
                return n
            }, f.prototype.polylineToPath = function(e) {
                var n = {},
                    t = e.points.trim().split(" "),
                    o, l;
                if (e.points.indexOf(",") === -1) {
                    var d = [];
                    for (o = 0; o < t.length; o += 2) d.push(t[o] + "," + t[o + 1]);
                    t = d
                }
                for (l = "M" + t[0], o = 1; o < t.length; o++) t[o].indexOf(",") !== -1 && (l += "L" + t[o]);
                return n.d = l, n
            }, f.prototype.polygonToPath = function(e) {
                var n = f.prototype.polylineToPath(e);
                return n.d += "Z", n
            }, f.prototype.ellipseToPath = function(e) {
                var n = {},
                    t = parseFloat(e.rx) || 0,
                    o = parseFloat(e.ry) || 0,
                    l = parseFloat(e.cx) || 0,
                    d = parseFloat(e.cy) || 0,
                    p = l - t,
                    g = d,
                    k = parseFloat(l) + parseFloat(t),
                    R = d;
                return n.d = "M" + p + "," + g + "A" + t + "," + o + " 0,1,1 " + k + "," + R + "A" + t + "," + o + " 0,1,1 " + p + "," + R, n
            }, f.prototype.circleToPath = function(e) {
                var n = {},
                    t = parseFloat(e.r) || 0,
                    o = parseFloat(e.cx) || 0,
                    l = parseFloat(e.cy) || 0,
                    d = o - t,
                    p = l,
                    g = parseFloat(o) + parseFloat(t),
                    k = l;
                return n.d = "M" + d + "," + p + "A" + t + "," + t + " 0,1,1 " + g + "," + k + "A" + t + "," + t + " 0,1,1 " + d + "," + k, n
            }, f.prototype.pathMaker = function(e, n) {
                var t, o, l = document.createElementNS("http://www.w3.org/2000/svg", "path");
                for (t = 0; t < e.attributes.length; t++) o = e.attributes[t], this.ATTR_WATCH.indexOf(o.name) === -1 && l.setAttribute(o.name, o.value);
                for (t in n) l.setAttribute(t, n[t]);
                return l
            }, f.prototype.parseAttr = function(e) {
                for (var n, t = {}, o = 0; o < e.length; o++) {
                    if (n = e[o], this.ATTR_WATCH.indexOf(n.name) !== -1 && n.value.indexOf("%") !== -1) throw new Error("Pathformer [parseAttr]: a SVG shape got values in percentage. This cannot be transformed into 'path' tags. Please use 'viewBox'.");
                    t[n.name] = n.value
                }
                return t
            };
            var w, r, A, h;

            function u(e, n, t) {
                w(), this.isReady = !1, this.setElement(e, n), this.setOptions(n), this.setCallback(t), this.isReady && this.init()
            }
            u.LINEAR = function(e) {
                return e
            }, u.EASE = function(e) {
                return -Math.cos(e * Math.PI) / 2 + .5
            }, u.EASE_OUT = function(e) {
                return 1 - Math.pow(1 - e, 3)
            }, u.EASE_IN = function(e) {
                return Math.pow(e, 3)
            }, u.EASE_OUT_BOUNCE = function(e) {
                var n = -Math.cos(e * (.5 * Math.PI)) + 1,
                    t = Math.pow(n, 1.5),
                    o = Math.pow(1 - e, 2),
                    l = -Math.abs(Math.cos(t * (2.5 * Math.PI))) + 1;
                return 1 - o + l * o
            }, u.prototype.setElement = function(e, n) {
                var t, o;
                if (typeof e > "u") throw new Error('Vivus [constructor]: "element" parameter is required');
                if (e.constructor === String && (e = document.getElementById(e), !e)) throw new Error('Vivus [constructor]: "element" parameter is not related to an existing ID');
                if (this.parentEl = e, n && n.file) {
                    var o = this;
                    t = function(p) {
                        var g = document.createElement("div");
                        g.innerHTML = this.responseText;
                        var k = g.querySelector("svg");
                        if (!k) throw new Error("Vivus [load]: Cannot find the SVG in the loaded file : " + n.file);
                        o.el = k, o.el.setAttribute("width", "100%"), o.el.setAttribute("height", "100%"), o.parentEl.appendChild(o.el), o.isReady = !0, o.init(), o = null
                    };
                    var l = new window.XMLHttpRequest;
                    l.addEventListener("load", t), l.open("GET", n.file), l.send();
                    return
                }
                switch (e.constructor) {
                    case window.SVGSVGElement:
                    case window.SVGElement:
                    case window.SVGGElement:
                        this.el = e, this.isReady = !0;
                        break;
                    case window.HTMLObjectElement:
                        o = this, t = function(d) {
                            if (!o.isReady) {
                                if (o.el = e.contentDocument && e.contentDocument.querySelector("svg"), !o.el && d) throw new Error("Vivus [constructor]: object loaded does not contain any SVG");
                                o.el && (e.getAttribute("built-by-vivus") && (o.parentEl.insertBefore(o.el, e), o.parentEl.removeChild(e), o.el.setAttribute("width", "100%"), o.el.setAttribute("height", "100%")), o.isReady = !0, o.init(), o = null)
                            }
                        }, t() || e.addEventListener("load", t);
                        break;
                    default:
                        throw new Error('Vivus [constructor]: "element" parameter is not valid (or miss the "file" attribute)')
                }
            }, u.prototype.setOptions = function(e) {
                var n = ["delayed", "sync", "async", "nsync", "oneByOne", "scenario", "scenario-sync"],
                    t = ["inViewport", "manual", "autostart"];
                if (e !== void 0 && e.constructor !== Object) throw new Error('Vivus [constructor]: "options" parameter must be an object');
                if (e = e || {}, e.type && n.indexOf(e.type) === -1) throw new Error("Vivus [constructor]: " + e.type + " is not an existing animation `type`");
                if (this.type = e.type || n[0], e.start && t.indexOf(e.start) === -1) throw new Error("Vivus [constructor]: " + e.start + " is not an existing `start` option");
                if (this.start = e.start || t[0], this.isIE = window.navigator.userAgent.indexOf("MSIE") !== -1 || window.navigator.userAgent.indexOf("Trident/") !== -1 || window.navigator.userAgent.indexOf("Edge/") !== -1, this.duration = h(e.duration, 120), this.delay = h(e.delay, null), this.dashGap = h(e.dashGap, 1), this.forceRender = e.hasOwnProperty("forceRender") ? !!e.forceRender : this.isIE, this.reverseStack = !!e.reverseStack, this.selfDestroy = !!e.selfDestroy, this.onReady = e.onReady, this.map = [], this.frameLength = this.currentFrame = this.delayUnit = this.speed = this.handle = null, this.ignoreInvisible = e.hasOwnProperty("ignoreInvisible") ? !!e.ignoreInvisible : !1, this.animTimingFunction = e.animTimingFunction || u.LINEAR, this.pathTimingFunction = e.pathTimingFunction || u.LINEAR, this.delay >= this.duration) throw new Error("Vivus [constructor]: delay must be shorter than duration")
            }, u.prototype.setCallback = function(e) {
                if (e && e.constructor !== Function) throw new Error('Vivus [constructor]: "callback" parameter must be a function');
                this.callback = e || function() {}
            }, u.prototype.mapping = function() {
                var e, n, t, o, l, d, p, g;
                for (g = d = p = 0, n = this.el.querySelectorAll("path"), e = 0; e < n.length; e++)
                    if (t = n[e], !this.isInvisible(t)) {
                        if (l = {
                                el: t,
                                length: Math.ceil(t.getTotalLength())
                            }, isNaN(l.length)) {
                            window.console && console.warn && console.warn("Vivus [mapping]: cannot retrieve a path element length", t);
                            continue
                        }
                        this.map.push(l), t.style.strokeDasharray = l.length + " " + (l.length + this.dashGap * 2), t.style.strokeDashoffset = l.length + this.dashGap, l.length += this.dashGap, d += l.length, this.renderPath(e)
                    } for (d = d === 0 ? 1 : d, this.delay = this.delay === null ? this.duration / 3 : this.delay, this.delayUnit = this.delay / (n.length > 1 ? n.length - 1 : 1), this.reverseStack && this.map.reverse(), e = 0; e < this.map.length; e++) {
                    switch (l = this.map[e], this.type) {
                        case "delayed":
                            l.startAt = this.delayUnit * e, l.duration = this.duration - this.delay;
                            break;
                        case "oneByOne":
                            l.startAt = p / d * this.duration, l.duration = l.length / d * this.duration;
                            break;
                        case "sync":
                        case "async":
                        case "nsync":
                            l.startAt = 0, l.duration = this.duration;
                            break;
                        case "scenario-sync":
                            t = l.el, o = this.parseAttr(t), l.startAt = g + (h(o["data-delay"], this.delayUnit) || 0), l.duration = h(o["data-duration"], this.duration), g = o["data-async"] !== void 0 ? l.startAt : l.startAt + l.duration, this.frameLength = Math.max(this.frameLength, l.startAt + l.duration);
                            break;
                        case "scenario":
                            t = l.el, o = this.parseAttr(t), l.startAt = h(o["data-start"], this.delayUnit) || 0, l.duration = h(o["data-duration"], this.duration), this.frameLength = Math.max(this.frameLength, l.startAt + l.duration);
                            break
                    }
                    p += l.length, this.frameLength = this.frameLength || this.duration
                }
            }, u.prototype.drawer = function() {
                var e = this;
                if (this.currentFrame += this.speed, this.currentFrame <= 0) this.stop(), this.reset();
                else if (this.currentFrame >= this.frameLength) this.stop(), this.currentFrame = this.frameLength, this.trace(), this.selfDestroy && this.destroy();
                else {
                    this.trace(), this.handle = r(function() {
                        e.drawer()
                    });
                    return
                }
                this.callback(this), this.instanceCallback && (this.instanceCallback(this), this.instanceCallback = null)
            }, u.prototype.trace = function() {
                var e, n, t, o;
                for (o = this.animTimingFunction(this.currentFrame / this.frameLength) * this.frameLength, e = 0; e < this.map.length; e++) t = this.map[e], n = (o - t.startAt) / t.duration, n = this.pathTimingFunction(Math.max(0, Math.min(1, n))), t.progress !== n && (t.progress = n, t.el.style.strokeDashoffset = Math.floor(t.length * (1 - n)), this.renderPath(e))
            }, u.prototype.renderPath = function(e) {
                if (this.forceRender && this.map && this.map[e]) {
                    var n = this.map[e],
                        t = n.el.cloneNode(!0);
                    n.el.parentNode.replaceChild(t, n.el), n.el = t
                }
            }, u.prototype.init = function() {
                this.frameLength = 0, this.currentFrame = 0, this.map = [], new f(this.el), this.mapping(), this.starter(), this.onReady && this.onReady(this)
            }, u.prototype.starter = function() {
                switch (this.start) {
                    case "manual":
                        return;
                    case "autostart":
                        this.play();
                        break;
                    case "inViewport":
                        var e = this,
                            n = function() {
                                e.isInViewport(e.parentEl, 1) && (e.play(), window.removeEventListener("scroll", n))
                            };
                        window.addEventListener("scroll", n), n();
                        break
                }
            }, u.prototype.getStatus = function() {
                return this.currentFrame === 0 ? "start" : this.currentFrame === this.frameLength ? "end" : "progress"
            }, u.prototype.reset = function() {
                return this.setFrameProgress(0)
            }, u.prototype.finish = function() {
                return this.setFrameProgress(1)
            }, u.prototype.setFrameProgress = function(e) {
                return e = Math.min(1, Math.max(0, e)), this.currentFrame = Math.round(this.frameLength * e), this.trace(), this
            }, u.prototype.play = function(e, n) {
                if (this.instanceCallback = null, e && typeof e == "function") this.instanceCallback = e, e = null;
                else if (e && typeof e != "number") throw new Error("Vivus [play]: invalid speed");
                return n && typeof n == "function" && !this.instanceCallback && (this.instanceCallback = n), this.speed = e || 1, this.handle || this.drawer(), this
            }, u.prototype.stop = function() {
                return this.handle && (A(this.handle), this.handle = null), this
            }, u.prototype.destroy = function() {
                this.stop();
                var e, n;
                for (e = 0; e < this.map.length; e++) n = this.map[e], n.el.style.strokeDashoffset = null, n.el.style.strokeDasharray = null, this.renderPath(e)
            }, u.prototype.isInvisible = function(e) {
                var n, t = e.getAttribute("data-ignore");
                return t !== null ? t !== "false" : this.ignoreInvisible ? (n = e.getBoundingClientRect(), !n.width && !n.height) : !1
            }, u.prototype.parseAttr = function(e) {
                var n, t = {};
                if (e && e.attributes)
                    for (var o = 0; o < e.attributes.length; o++) n = e.attributes[o], t[n.name] = n.value;
                return t
            }, u.prototype.isInViewport = function(e, n) {
                var t = this.scrollY(),
                    o = t + this.getViewportH(),
                    l = e.getBoundingClientRect(),
                    d = l.height,
                    p = t + l.top,
                    g = p + d;
                return n = n || 0, p + d * n <= o && g >= t
            }, u.prototype.getViewportH = function() {
                var e = this.docElem.clientHeight,
                    n = window.innerHeight;
                return e < n ? n : e
            }, u.prototype.scrollY = function() {
                return window.pageYOffset || this.docElem.scrollTop
            }, w = function() {
                u.prototype.docElem || (u.prototype.docElem = window.document.documentElement, r = (function() {
                    return window.requestAnimationFrame || window.webkitRequestAnimationFrame || window.mozRequestAnimationFrame || window.oRequestAnimationFrame || window.msRequestAnimationFrame || function(e) {
                        return window.setTimeout(e, 1e3 / 60)
                    }
                })(), A = (function() {
                    return window.cancelAnimationFrame || window.webkitCancelAnimationFrame || window.mozCancelAnimationFrame || window.oCancelAnimationFrame || window.msCancelAnimationFrame || function(e) {
                        return window.clearTimeout(e)
                    }
                })())
            }, h = function(e, n) {
                var t = parseInt(e, 10);
                return t >= 0 ? t : n
            }, a.exports = u
        })()
    })(L)), L.exports
}
var re = ne();
const oe = G(re),
    se = {
        props: ["file", "id"],
        mounted() {
            new oe(this.id, {
                duration: 200,
                file: this.file,
                start: "autostart"
            })
        }
    },
    le = ["id"];

function ce(a, s, f, w, r, A) {
    return m(), y("div", {
        id: f.id
    }, null, 8, le)
}
const ue = P(se, [
        ["render", ce]
    ]),
    de = ["Africa/Abidjan", "Africa/Accra", "Africa/Addis_Ababa", "Africa/Algiers", "Africa/Asmara", "Africa/Asmera", "Africa/Bamako", "Africa/Bangui", "Africa/Banjul", "Africa/Bissau", "Africa/Blantyre", "Africa/Brazzaville", "Africa/Bujumbura", "Africa/Cairo", "Africa/Casablanca", "Africa/Ceuta", "Africa/Conakry", "Africa/Dakar", "Africa/Dar_es_Salaam", "Africa/Djibouti", "Africa/Douala", "Africa/El_Aaiun", "Africa/Freetown", "Africa/Gaborone", "Africa/Harare", "Africa/Johannesburg", "Africa/Juba", "Africa/Kampala", "Africa/Khartoum", "Africa/Kigali", "Africa/Kinshasa", "Africa/Lagos", "Africa/Libreville", "Africa/Lome", "Africa/Luanda", "Africa/Lubumbashi", "Africa/Lusaka", "Africa/Malabo", "Africa/Maputo", "Africa/Maseru", "Africa/Mbabane", "Africa/Mogadishu", "Africa/Monrovia", "Africa/Nairobi", "Africa/Ndjamena", "Africa/Niamey", "Africa/Nouakchott", "Africa/Ouagadougou", "Africa/Porto-Novo", "Africa/Sao_Tome", "Africa/Timbuktu", "Africa/Tripoli", "Africa/Tunis", "Africa/Windhoek", "America/Adak", "America/Anchorage", "America/Anguilla", "America/Antigua", "America/Araguaina", "America/Argentina/Buenos_Aires", "America/Argentina/Catamarca", "America/Argentina/ComodRivadavia", "America/Argentina/Cordoba", "America/Argentina/Jujuy", "America/Argentina/La_Rioja", "America/Argentina/Mendoza", "America/Argentina/Rio_Gallegos", "America/Argentina/Salta", "America/Argentina/San_Juan", "America/Argentina/San_Luis", "America/Argentina/Tucuman", "America/Argentina/Ushuaia", "America/Aruba", "America/Asuncion", "America/Atikokan", "America/Atka", "America/Bahia", "America/Bahia_Banderas", "America/Barbados", "America/Belem", "America/Belize", "America/Blanc-Sablon", "America/Boa_Vista", "America/Bogota", "America/Boise", "America/Buenos_Aires", "America/Cambridge_Bay", "America/Campo_Grande", "America/Cancun", "America/Caracas", "America/Catamarca", "America/Cayenne", "America/Cayman", "America/Chicago", "America/Chihuahua", "America/Ciudad_Juarez", "America/Coral_Harbour", "America/Cordoba", "America/Costa_Rica", "America/Coyhaique", "America/Creston", "America/Cuiaba", "America/Curacao", "America/Danmarkshavn", "America/Dawson", "America/Dawson_Creek", "America/Denver", "America/Detroit", "America/Dominica", "America/Edmonton", "America/Eirunepe", "America/El_Salvador", "America/Ensenada", "America/Fort_Nelson", "America/Fort_Wayne", "America/Fortaleza", "America/Glace_Bay", "America/Godthab", "America/Goose_Bay", "America/Grand_Turk", "America/Grenada", "America/Guadeloupe", "America/Guatemala", "America/Guayaquil", "America/Guyana", "America/Halifax", "America/Havana", "America/Hermosillo", "America/Indiana/Indianapolis", "America/Indiana/Knox", "America/Indiana/Marengo", "America/Indiana/Petersburg", "America/Indiana/Tell_City", "America/Indiana/Vevay", "America/Indiana/Vincennes", "America/Indiana/Winamac", "America/Indianapolis", "America/Inuvik", "America/Iqaluit", "America/Jamaica", "America/Jujuy", "America/Juneau", "America/Kentucky/Louisville", "America/Kentucky/Monticello", "America/Knox_IN", "America/Kralendijk", "America/La_Paz", "America/Lima", "America/Los_Angeles", "America/Louisville", "America/Lower_Princes", "America/Maceio", "America/Managua", "America/Manaus", "America/Marigot", "America/Martinique", "America/Matamoros", "America/Mazatlan", "America/Mendoza", "America/Menominee", "America/Merida", "America/Metlakatla", "America/Mexico_City", "America/Miquelon", "America/Moncton", "America/Monterrey", "America/Montevideo", "America/Montreal", "America/Montserrat", "America/Nassau", "America/New_York", "America/Nipigon", "America/Nome", "America/Noronha", "America/North_Dakota/Beulah", "America/North_Dakota/Center", "America/North_Dakota/New_Salem", "America/Nuuk", "America/Ojinaga", "America/Panama", "America/Pangnirtung", "America/Paramaribo", "America/Phoenix", "America/Port-au-Prince", "America/Port_of_Spain", "America/Porto_Acre", "America/Porto_Velho", "America/Puerto_Rico", "America/Punta_Arenas", "America/Rainy_River", "America/Rankin_Inlet", "America/Recife", "America/Regina", "America/Resolute", "America/Rio_Branco", "America/Rosario", "America/Santa_Isabel", "America/Santarem", "America/Santiago", "America/Santo_Domingo", "America/Sao_Paulo", "America/Scoresbysund", "America/Shiprock", "America/Sitka", "America/St_Barthelemy", "America/St_Johns", "America/St_Kitts", "America/St_Lucia", "America/St_Thomas", "America/St_Vincent", "America/Swift_Current", "America/Tegucigalpa", "America/Thule", "America/Thunder_Bay", "America/Tijuana", "America/Toronto", "America/Tortola", "America/Vancouver", "America/Virgin", "America/Whitehorse", "America/Winnipeg", "America/Yakutat", "America/Yellowknife", "Arctic/Longyearbyen", "Asia/Aden", "Asia/Almaty", "Asia/Amman", "Asia/Anadyr", "Asia/Aqtau", "Asia/Aqtobe", "Asia/Ashgabat", "Asia/Ashkhabad", "Asia/Atyrau", "Asia/Baghdad", "Asia/Bahrain", "Asia/Baku", "Asia/Bangkok", "Asia/Barnaul", "Asia/Beirut", "Asia/Bishkek", "Asia/Brunei", "Asia/Calcutta", "Asia/Chita", "Asia/Choibalsan", "Asia/Chongqing", "Asia/Chungking", "Asia/Colombo", "Asia/Dacca", "Asia/Damascus", "Asia/Dhaka", "Asia/Dili", "Asia/Dubai", "Asia/Dushanbe", "Asia/Famagusta", "Asia/Gaza", "Asia/Harbin", "Asia/Hebron", "Asia/Ho_Chi_Minh", "Asia/Hong_Kong", "Asia/Hovd", "Asia/Irkutsk", "Asia/Istanbul", "Asia/Jakarta", "Asia/Jayapura", "Asia/Jerusalem", "Asia/Kabul", "Asia/Kamchatka", "Asia/Karachi", "Asia/Kashgar", "Asia/Kathmandu", "Asia/Katmandu", "Asia/Khandyga", "Asia/Kolkata", "Asia/Krasnoyarsk", "Asia/Kuala_Lumpur", "Asia/Kuching", "Asia/Kuwait", "Asia/Macao", "Asia/Macau", "Asia/Magadan", "Asia/Makassar", "Asia/Manila", "Asia/Muscat", "Asia/Nicosia", "Asia/Novokuznetsk", "Asia/Novosibirsk", "Asia/Omsk", "Asia/Oral", "Asia/Phnom_Penh", "Asia/Pontianak", "Asia/Pyongyang", "Asia/Qatar", "Asia/Qostanay", "Asia/Qyzylorda", "Asia/Rangoon", "Asia/Riyadh", "Asia/Saigon", "Asia/Sakhalin", "Asia/Samarkand", "Asia/Seoul", "Asia/Shanghai", "Asia/Singapore", "Asia/Srednekolymsk", "Asia/Taipei", "Asia/Tashkent", "Asia/Tbilisi", "Asia/Tehran", "Asia/Tel_Aviv", "Asia/Thimbu", "Asia/Thimphu", "Asia/Tokyo", "Asia/Tomsk", "Asia/Ujung_Pandang", "Asia/Ulaanbaatar", "Asia/Ulan_Bator", "Asia/Urumqi", "Asia/Ust-Nera", "Asia/Vientiane", "Asia/Vladivostok", "Asia/Yakutsk", "Asia/Yangon", "Asia/Yekaterinburg", "Asia/Yerevan", "Atlantic/Azores", "Atlantic/Bermuda", "Atlantic/Canary", "Atlantic/Cape_Verde", "Atlantic/Faeroe", "Atlantic/Faroe", "Atlantic/Jan_Mayen", "Atlantic/Madeira", "Atlantic/Reykjavik", "Atlantic/South_Georgia", "Atlantic/St_Helena", "Atlantic/Stanley", "Europe/Amsterdam", "Europe/Andorra", "Europe/Astrakhan", "Europe/Athens", "Europe/Belfast", "Europe/Belgrade", "Europe/Berlin", "Europe/Bratislava", "Europe/Brussels", "Europe/Bucharest", "Europe/Budapest", "Europe/Busingen", "Europe/Chisinau", "Europe/Copenhagen", "Europe/Dublin", "Europe/Gibraltar", "Europe/Guernsey", "Europe/Helsinki", "Europe/Isle_of_Man", "Europe/Istanbul", "Europe/Jersey", "Europe/Kaliningrad", "Europe/Kiev", "Europe/Kirov", "Europe/Kyiv", "Europe/Lisbon", "Europe/Ljubljana", "Europe/London", "Europe/Luxembourg", "Europe/Madrid", "Europe/Malta", "Europe/Mariehamn", "Europe/Minsk", "Europe/Monaco", "Europe/Moscow", "Europe/Nicosia", "Europe/Oslo", "Europe/Paris", "Europe/Podgorica", "Europe/Prague", "Europe/Riga", "Europe/Rome", "Europe/Samara", "Europe/San_Marino", "Europe/Sarajevo", "Europe/Saratov", "Europe/Simferopol", "Europe/Skopje", "Europe/Sofia", "Europe/Stockholm", "Europe/Tallinn", "Europe/Tirane", "Europe/Tiraspol", "Europe/Ulyanovsk", "Europe/Uzhgorod", "Europe/Vaduz", "Europe/Vatican", "Europe/Vienna", "Europe/Vilnius", "Europe/Volgograd", "Europe/Warsaw", "Europe/Zagreb", "Europe/Zaporozhye", "Europe/Zurich", "Indian/Antananarivo", "Indian/Chagos", "Indian/Christmas", "Indian/Cocos", "Indian/Comoro", "Indian/Kerguelen", "Indian/Mahe", "Indian/Maldives", "Indian/Mauritius", "Indian/Mayotte", "Indian/Reunion", "Pacific/Apia", "Pacific/Auckland", "Pacific/Bougainville", "Pacific/Chatham", "Pacific/Chuuk", "Pacific/Easter", "Pacific/Efate", "Pacific/Enderbury", "Pacific/Fakaofo", "Pacific/Fiji", "Pacific/Funafuti", "Pacific/Galapagos", "Pacific/Gambier", "Pacific/Guadalcanal", "Pacific/Guam", "Pacific/Honolulu", "Pacific/Johnston", "Pacific/Kanton", "Pacific/Kiritimati", "Pacific/Kosrae", "Pacific/Kwajalein", "Pacific/Majuro", "Pacific/Marquesas", "Pacific/Midway", "Pacific/Nauru", "Pacific/Niue", "Pacific/Norfolk", "Pacific/Noumea", "Pacific/Pago_Pago", "Pacific/Palau", "Pacific/Pitcairn", "Pacific/Pohnpei", "Pacific/Ponape", "Pacific/Port_Moresby", "Pacific/Rarotonga", "Pacific/Saipan", "Pacific/Samoa", "Pacific/Tahiti", "Pacific/Tarawa", "Pacific/Tongatapu", "Pacific/Truk", "Pacific/Wake", "Pacific/Wallis", "Pacific/Yap"],
    me = {
        components: {
            SelectForSubjects: te
        },
        props: ["timezone"],
        data() {
            return {
                chosenTimezone: void 0
            }
        },
        computed: {
            availableTimezones() {
                return de.map(function(a) {
                    return {
                        id: a,
                        name: a,
                        offset: T.now().setZone(a).offset / 60,
                        currentTime: T.now().setZone(a).toLocaleString(T.TIME_SIMPLE)
                    }
                }).sort(function(a, s) {
                    return a.offset == s.offset ? a.name < s.name ? -1 : 1 : a.offset - s.offset
                })
            }
        },
        mounted() {
            this.chosenTimezone = this.timezone ? {
                id: this.timezone
            } : void 0
        },
        methods: {
            updateTimezone(a) {
                q.defaultZone = a.id, M.patch("users/current", {
                    timezone: a.id,
                    action: "change:userTimezone"
                })
            }
        }
    },
    he = {
        class: "timezone-picker"
    };

function fe(a, s, f, w, r, A) {
    const h = _("SelectForSubjects");
    return m(), y("span", he, [v(h, {
        modelValue: r.chosenTimezone,
        "onUpdate:modelValue": s[0] || (s[0] = u => r.chosenTimezone = u),
        class: "timezones-dropdown",
        "do-not-hide-selected": "",
        options: A.availableTimezones,
        caption: u => `${u.name} (UTC${u.offset>=0?"+":""}${u.offset}) ${u.currentTime}`,
        "choose-prompt-i18n": "choose the timezone",
        onInput: s[1] || (s[1] = u => A.updateTimezone(u))
    }, null, 8, ["modelValue", "options", "caption"])])
}
const pe = P(me, [
        ["render", fe]
    ]),
    Ae = {
        components: {
            ModalConfirm: z,
            AccessIdsDropdown: ie
        },
        props: ["user"],
        data() {
            return {
                loading: !1,
                possibleNotifications: [{
                    id: "failed_auth_attempt",
                    label: "Unsuccessful login attempt"
                }, {
                    id: "new_io_device",
                    label: "New IO device added to your account"
                }, {
                    id: "new_client_app",
                    label: "New client app (smartphone) added to your account"
                }],
                selectedNotificationsEmail: {},
                selectedNotificationsPush: {},
                accessIds: []
            }
        },
        computed: {
            currentOptOutNotification() {
                return this.$route.query.optOutNotification
            },
            notificationsEnabled() {
                return this.frontendConfig.notificationsEnabled
            },
            ...V(I, {
                frontendConfig: "config"
            })
        },
        mounted() {
            this.possibleNotifications.forEach(({
                id: a
            }) => {
                const s = this.user.preferences?.optOutNotifications?.includes(a),
                    f = this.user.preferences?.optOutNotificationsPush?.includes(a);
                this.$set(this.selectedNotificationsEmail, a, !s), this.$set(this.selectedNotificationsPush, a, !f)
            }), this.user.preferences?.accountPushNotificationsAccessIdsIds?.forEach(a => this.accessIds.push({
                id: a
            }))
        },
        methods: {
            updateOptOutNotifications() {
                this.loading = !0;
                const a = this.possibleNotifications.map(({
                        id: w
                    }) => w).filter(w => !this.selectedNotificationsEmail[w]),
                    s = this.possibleNotifications.map(({
                        id: w
                    }) => w).filter(w => !this.selectedNotificationsPush[w]),
                    f = this.accessIds.map(({
                        id: w
                    }) => w);
                M.patch("users/current", {
                    action: "change:optOutNotifications",
                    optOutNotifications: a,
                    optOutNotificationsPush: s,
                    accountPushNotificationsAccessIdsIds: f
                }).then(({
                    body: w
                }) => {
                    this.user.preferences = w.preferences, O(this.$t("Your preferences has been updated.")), this.$emit("cancel")
                }).finally(() => this.loading = !1)
            }
        }
    },
    ge = {
        key: 0
    },
    ye = {
        class: "table table-striped"
    },
    ve = {
        key: 0
    },
    we = {
        class: "checkbox2"
    },
    be = ["onUpdate:modelValue"],
    _e = {
        key: 0
    },
    Ee = {
        class: "checkbox2"
    },
    ke = ["onUpdate:modelValue"],
    Ce = {
        class: "form-group"
    };

function Se(a, s, f, w, r, A) {
    const h = _("AccessIdsDropdown"),
        u = _("modal-confirm");
    return m(), S(u, {
        loading: r.loading,
        header: a.$t("Account notifications"),
        onConfirm: s[2] || (s[2] = e => A.updateOptOutNotifications()),
        onCancel: s[3] || (s[3] = e => a.$emit("cancel"))
    }, {
        default: E(() => [i("p", null, c(a.$t("We can notify you about certain events with your account. Opt out if you don't want us to bother you.")), 1), A.currentOptOutNotification ? (m(), y("p", ge, c(a.$t("The notification marked with an orange background is the one you saw when you clicked the unsubscribe link.")), 1)) : b("", !0), i("form", {
            class: "opt-out-notifications",
            onSubmit: s[1] || (s[1] = K(e => A.updateOptOutNotifications(), ["prevent"]))
        }, [i("table", ye, [i("thead", null, [i("tr", null, [s[4] || (s[4] = i("th", null, null, -1)), i("th", null, c(a.$t("E-mail")), 1), A.notificationsEnabled ? (m(), y("th", ve, c(a.$t("Push")), 1)) : b("", !0)])]), i("tbody", null, [(m(!0), y(H, null, U(r.possibleNotifications, e => (m(), y("tr", {
            key: e.id,
            class: C({
                "current-opt-out-notification": A.currentOptOutNotification == e.id
            })
        }, [i("td", null, c(a.$t(e.label)), 1), i("td", null, [i("label", we, [N(i("input", {
            "onUpdate:modelValue": n => r.selectedNotificationsEmail[e.id] = n,
            type: "checkbox"
        }, null, 8, be), [
            [B, r.selectedNotificationsEmail[e.id]]
        ])])]), A.notificationsEnabled ? (m(), y("td", _e, [i("label", Ee, [N(i("input", {
            "onUpdate:modelValue": n => r.selectedNotificationsPush[e.id] = n,
            type: "checkbox"
        }, null, 8, ke), [
            [B, r.selectedNotificationsPush[e.id]]
        ])])])) : b("", !0)], 2))), 128))])]), i("div", Ce, [i("label", null, c(a.$t("Recipients for push notifications")), 1), v(h, {
            modelValue: r.accessIds,
            "onUpdate:modelValue": s[0] || (s[0] = e => r.accessIds = e)
        }, null, 8, ["modelValue"])]), s[5] || (s[5] = i("button", {
            class: "hidden",
            type: "submit"
        }, null, -1))], 32)], void 0, !0),
        _: 1
    }, 8, ["loading", "header"])
}
const Pe = P(Ae, [
        ["render", Se]
    ]),
    Te = {
        components: {
            Modal: D,
            ButtonLoadingDots: j
        },
        data() {
            return {
                password: "",
                loading: !1
            }
        },
        methods: {
            deleteAccount() {
                if (!this.password) return Y(this.$t("Incorrect password"));
                this.loading = !0, M.patch("users/current", {
                    action: "delete",
                    password: this.password
                }).then(() => {
                    O(this.$t("We have sent you an e-mail message with a delete confirmation link. Just to be sure!")), this.$emit("cancel"), document.getElementById("logoutButton").dispatchEvent(new MouseEvent("click"))
                }).finally(() => this.loading = !1)
            }
        }
    },
    Me = {
        class: "text-center"
    },
    Ne = {
        class: "text-center"
    },
    Le = {
        for: "password"
    },
    Ie = {
        key: 1
    };

function Re(a, s, f, w, r, A) {
    const h = _("button-loading-dots"),
        u = _("modal");
    return m(), S(u, {
        class: "modal-warning",
        header: a.$t("We will miss you"),
        onCancel: s[3] || (s[3] = e => a.$emit("cancel"))
    }, {
        footer: E(() => [r.loading ? (m(), S(h, {
            key: 0
        })) : (m(), y("div", Ie, [i("a", {
            class: "btn btn-grey",
            onClick: s[1] || (s[1] = e => a.$emit("cancel"))
        }, c(a.$t("Cancel")), 1), i("a", {
            class: "btn btn-red-outline",
            onClick: s[2] || (s[2] = e => A.deleteAccount())
        }, c(a.$t("I confirm! Delete my account.")), 1)]))]),
        default: E(() => [i("p", Me, c(a.$t("Deleting your account will result also in deletion of all your data, including your connected devices, configured channels, direct links and measurement history. Deleting an account is irreversible.")), 1), i("p", Ne, c(a.$t("In order to confirm account deletion, enter your password.")), 1), N(i("input", {
            id: "password",
            "onUpdate:modelValue": s[0] || (s[0] = e => r.password = e),
            type: "password",
            autocomplete: "new-password",
            class: "form-control"
        }, null, 512), [
            [J, r.password]
        ]), i("label", Le, c(a.$t("Password")), 1)], void 0, !0),
        _: 1
    }, 8, ["header"])
}
const Be = P(Te, [
        ["render", Re]
    ]),
    Fe = {
        props: ["value", "limit"],
        computed: {
            progress() {
                return this.value * 100 / this.limit
            },
            progressBarClass() {
                return this.progress < 50 ? "success" : this.progress < 80 ? "warning" : "danger"
            }
        }
    },
    Ve = ["aria-valuenow", "aria-valuemax"];

function Oe(a, s, f, w, r, A) {
    return m(), y("div", {
        class: C("progress-bar account-limit-progressbar progress-bar-" + A.progressBarClass),
        role: "progressbar",
        "aria-valuemin": "0",
        "aria-valuenow": f.value,
        "aria-valuemax": f.limit,
        style: Z({
            width: A.progress + "%"
        })
    }, [W(a.$slots, "default", {}, () => [x(c(f.value) + "/" + c(f.limit), 1)])], 14, Ve)
}
const De = P(Fe, [
        ["render", Oe]
    ]),
    Ge = [{
        field: "ioDevice",
        label: "I/O Devices",
        progressLimit: a => a.ioDevice,
        progressValue: (a, s) => s.ioDevices
    }, {
        field: "accessId",
        label: "Access Identifiers",
        progressLimit: a => a.accessId,
        progressValue: (a, s) => s.accessIds
    }, {
        field: "clientApp",
        label: "Client’s Apps",
        progressLimit: a => a.clientApp,
        progressValue: (a, s) => s.clientApps
    }, {
        field: "channelGroup",
        label: "Channel groups",
        progressLimit: a => a.channelGroup,
        progressValue: (a, s) => s.channelGroups
    }, {
        field: "location",
        label: "Locations",
        progressLimit: a => a.location,
        progressValue: (a, s) => s.locations
    }, {
        field: "schedule",
        label: "Schedules",
        progressLimit: a => a.schedule,
        progressValue: (a, s) => s.schedules
    }, {
        field: "scene",
        label: "Scenes",
        progressLimit: a => a.scene,
        progressValue: (a, s) => s.scenes
    }, {
        field: "directLink",
        label: "Direct links",
        progressLimit: a => a.directLink,
        progressValue: (a, s) => s.directLinks
    }, {
        field: "valueBasedTriggers",
        label: "Reactions",
        progressLimit: a => a.valueBasedTriggers,
        progressValue: (a, s) => s.valueBasedTriggers
    }, {
        field: "virtualChannels",
        label: "Data source channels",
        progressLimit: a => a.virtualChannels,
        progressValue: (a, s) => s.virtualChannels
    }, {
        field: "oauthClient",
        label: "OAuth apps",
        progressLimit: a => a.oauthClient,
        progressValue: (a, s) => s.apiClients
    }, {
        field: "pushNotifications",
        label: "Notifications (defined)",
        progressLimit: a => a.pushNotifications,
        progressValue: (a, s) => s.pushNotifications,
        notificationsOnly: !0
    }, {
        field: "pushNotificationsPerHour",
        label: "Notifications (sent per hour)",
        progressLimit: a => a.pushNotificationsPerHour.limit,
        progressValue: a => a.pushNotificationsPerHour.limit - a.pushNotificationsPerHour.left,
        notificationsOnly: !0
    }],
    qe = [{
        field: "channelPerGroup",
        label: "Max channels per group"
    }, {
        field: "actionsPerSchedule",
        label: "Max actions per schedule"
    }, {
        field: "operationsPerScene",
        label: "Max operations per scene"
    }],
    $e = {
        components: {
            Modal: D,
            LoadingCover: $,
            AccountLimitProgressbar: De
        },
        props: ["user"],
        data() {
            return {
                fetching: !1,
                saving: !1,
                limits: void 0,
                relationsCount: void 0,
                apiRateStatus: void 0,
                currentTab: "features",
                draftLimits: {},
                draftApiRateLimit: ""
            }
        },
        mounted() {
            this.fetchLimits()
        },
        methods: {
            handleConfirm() {
                this.currentTab === "edit" && this.canEditLimits ? this.saveLimits() : this.$emit("confirm")
            },
            handleCancel() {
                this.currentTab === "edit" ? this.closeEditor() : this.$emit("confirm")
            },
            openEditor() {
                this.resetDraft(), this.currentTab = "edit"
            },
            closeEditor() {
                this.resetDraft(), this.currentTab = "features"
            },
            fetchLimits() {
                this.fetching || this.saving || (this.fetching = !0, M.get("users/current?include=limits,relationsCount").then(a => this.applyUserData(a.body)).finally(() => this.fetching = !1))
            },
            applyUserData(a) {
                this.limits = {
                    ...a.limits,
                    apiRateLimit: a.apiRateLimit
                }, this.relationsCount = a.relationsCount, this.apiRateStatus = this.limits.apiRateLimit ? {
                    requests: this.limits.apiRateLimit.rule.limit - this.limits.apiRateLimit.status.remaining,
                    limit: this.limits.apiRateLimit.rule.limit,
                    seconds: this.limits.apiRateLimit.rule.period
                } : void 0, this.resetDraft()
            },
            resetDraft() {
                this.limits && (this.draftLimits = Object.fromEntries(this.editableLimitFields.map(({
                    field: a
                }) => [a, this.readLimitValue(a)])), this.draftApiRateLimit = this.limits.apiRateLimit ? `${this.limits.apiRateLimit.rule.limit}/${this.limits.apiRateLimit.rule.period}` : "")
            },
            readLimitValue(a) {
                return a === "pushNotificationsPerHour" ? this.limits.pushNotificationsPerHour.limit : this.limits[a]
            },
            serializeDraftLimit(a) {
                const s = Number.parseInt(this.draftLimits[a], 10);
                return Number.isNaN(s) ? void 0 : s
            },
            saveLimits() {
                if (this.fetching || this.saving) return;
                const a = {};
                for (const {
                        field: s
                    }
                    of this.editableLimitFields) {
                    const f = this.serializeDraftLimit(s);
                    if (f === void 0 || f < 0) {
                        Y(this.$t("Please fill all the fields"));
                        return
                    }
                    a[s] = f
                }
                this.saving = !0, M.patch("users/current", {
                    action: "change:limits",
                    limits: a,
                    apiRateLimit: this.draftApiRateLimit.trim()
                }).then(t => {
                    this.applyUserData(t.body), this.currentTab = "features", O(this.$t("Data saved"))
                }).finally(() => this.saving = !1)
            }
        },
        computed: {
            apiRateStatusReset() {
                if (this.apiRateStatus) return T.fromSeconds(this.limits.apiRateLimit.status.reset).toLocaleString(T.DATETIME_SHORT_WITH_SECONDS)
            },
            featureLimitFields() {
                return Ge.filter(a => !a.notificationsOnly || this.notificationsEnabled)
            },
            otherLimitFields() {
                return qe
            },
            editableLimitFields() {
                return [...this.featureLimitFields, ...this.otherLimitFields]
            },
            canEditLimits() {
                return !!this.frontendConfigStore.config.accountLimitsEditingEnabled
            },
            ...X(I),
            notificationsEnabled() {
                return this.frontendConfigStore.config.notificationsEnabled
            }
        }
    },
    ze = {
        key: 0
    },
    Ke = {
        class: "form-group"
    },
    He = {
        class: "nav nav-tabs"
    },
    Ue = {
        key: 0
    },
    je = {
        key: 0
    },
    Ye = {
        key: 1
    },
    Je = {
        class: "table"
    },
    We = {
        scope: "row"
    },
    xe = {
        scope: "row"
    },
    Ze = {
        scope: "row"
    },
    Xe = {
        scope: "row"
    },
    Qe = {
        key: 2
    },
    et = {
        class: "row"
    },
    tt = {
        class: "col-xs-12 api-rate-limit-progress"
    },
    it = {
        class: "well well-sm no-margin"
    },
    at = {
        class: "clearfix"
    },
    nt = {
        key: 0
    },
    rt = {
        key: 1
    },
    ot = {
        key: 2
    },
    st = {
        key: 3
    },
    lt = {
        key: 0,
        class: "text-right text-muted small"
    },
    ct = {
        key: 3
    },
    ut = {
        key: 4,
        class: "limits-editor"
    },
    att = {
        class: "row"
    },
    ntt = {
        class: "col-sm-6"
    },
    rtt = ["onUpdate:modelValue"],
    ott = {
        class: "col-sm-6"
    },
    stt = ["onUpdate:modelValue"],
    ltt = {
        class: "form-group"
    },
    ctt = {
        key: 0,
        class: "alert alert-info my-3"
    },
    utt = {
        class: "mb-2"
    };

function ftt(a, s, f, w, r, A) {
    const h = _("account-limit-progressbar"),
        u = _("loading-cover"),
        e = _("i18n-t"),
        n = _("modal");
    return m(), S(n, {
        class: "account-limits-modal modal-800",
        header: a.$t("Your account limits"),
        "display-close-button": !0,
        onConfirm: A.handleConfirm,
        onCancel: A.handleCancel
    }, {
        footer: E(() => [i("a", {
            class: "cancel small",
            onClick: s[6] || (s[6] = t => A.fetchLimits())
        }, [...s[12] || (s[12] = [i("i", {
            class: "pe-7s-refresh-2"
        }, null, -1)])]), A.canEditLimits && r.currentTab !== "edit" ? (m(), y("a", {
            key: 0,
            class: "cancel small",
            onClick: s[7] || (s[7] = t => A.openEditor())
        }, [...s[13] || (s[13] = [i("i", {
            class: "pe-7s-pen"
        }, null, -1)])])) : b("", !0), r.currentTab === "edit" ? (m(), y("a", {
            key: 1,
            class: "cancel small",
            onClick: s[8] || (s[8] = t => A.closeEditor())
        }, [...s[14] || (s[14] = [i("i", {
            class: "pe-7s-close"
        }, null, -1)])])) : b("", !0), i("a", {
            class: "confirm",
            onClick: s[9] || (s[9] = t => A.handleConfirm())
        }, [...s[15] || (s[15] = [i("i", {
            class: "pe-7s-check"
        }, null, -1)])])]),
        default: E(() => [v(u, {
            loading: r.fetching || r.saving
        }, {
            default: E(() => [r.limits ? (m(), y("div", ze, [i("div", Ke, [i("ul", He, [i("li", {
                class: C({
                    active: r.currentTab === "features"
                })
            }, [i("a", {
                onClick: s[0] || (s[0] = t => r.currentTab = "features")
            }, c(a.$t("Feature limits")), 1)], 2), i("li", {
                class: C({
                    active: r.currentTab === "data"
                })
            }, [i("a", {
                onClick: s[1] || (s[1] = t => r.currentTab = "data")
            }, c(a.$t("Data limits")), 1)], 2), r.apiRateStatus ? (m(), y("li", {
                key: 0,
                class: C({
                    active: r.currentTab === "api"
                })
            }, [i("a", {
                onClick: s[2] || (s[2] = t => r.currentTab = "api")
            }, c(a.$t("API rate limits")), 1)], 2)) : b("", !0), i("li", {
                class: C({
                    active: r.currentTab === "other"
                })
            }, [i("a", {
                onClick: s[3] || (s[3] = t => r.currentTab = "other")
            }, c(a.$t("Other")), 1)], 2), A.canEditLimits ? (m(), y("li", {
                key: 1,
                class: C({
                    active: r.currentTab === "edit"
                })
            }, [i("a", {
                onClick: s[4] || (s[4] = t => A.openEditor())
            }, " Edit ")], 2)) : b("", !0)])]), r.currentTab === "features" ? (m(), y("div", Ue, [i("dl", null, [i("dt", null, c(a.$t("I/O Devices")), 1), i("dd", null, [v(h, {
                limit: r.limits.ioDevice,
                value: r.relationsCount.ioDevices
            }, null, 8, ["limit", "value"])]), i("dt", null, c(a.$t("Access Identifiers")), 1), i("dd", null, [v(h, {
                limit: r.limits.accessId,
                value: r.relationsCount.accessIds
            }, null, 8, ["limit", "value"])]), i("dt", null, c(a.$t("Client’s Apps")), 1), i("dd", null, [v(h, {
                limit: r.limits.clientApp,
                value: r.relationsCount.clientApps
            }, null, 8, ["limit", "value"])]), i("dt", null, c(a.$t("Channel groups")), 1), i("dd", null, [v(h, {
                limit: r.limits.channelGroup,
                value: r.relationsCount.channelGroups
            }, null, 8, ["limit", "value"])]), i("dt", null, c(a.$t("Locations")), 1), i("dd", null, [v(h, {
                limit: r.limits.location,
                value: r.relationsCount.locations
            }, null, 8, ["limit", "value"])]), i("dt", null, c(a.$t("Schedules")), 1), i("dd", null, [v(h, {
                limit: r.limits.schedule,
                value: r.relationsCount.schedules
            }, null, 8, ["limit", "value"])]), i("dt", null, c(a.$t("Scenes")), 1), i("dd", null, [v(h, {
                limit: r.limits.scene,
                value: r.relationsCount.scenes
            }, null, 8, ["limit", "value"])]), i("dt", null, c(a.$t("Direct links")), 1), i("dd", null, [v(h, {
                limit: r.limits.directLink,
                value: r.relationsCount.directLinks
            }, null, 8, ["limit", "value"])]), i("dt", null, c(a.$t("Reactions")), 1), i("dd", null, [v(h, {
                limit: r.limits.valueBasedTriggers,
                value: r.relationsCount.valueBasedTriggers
            }, null, 8, ["limit", "value"])]), i("dt", null, c(a.$t("Data source channels")), 1), i("dd", null, [v(h, {
                limit: r.limits.virtualChannels,
                value: r.relationsCount.virtualChannels
            }, null, 8, ["limit", "value"])]), i("dt", null, c(a.$t("OAuth apps")), 1), i("dd", null, [v(h, {
                limit: r.limits.oauthClient,
                value: r.relationsCount.apiClients
            }, null, 8, ["limit", "value"])])]), A.notificationsEnabled ? (m(), y("dl", je, [i("dt", null, c(a.$t("Notifications (defined)")), 1), i("dd", null, [v(h, {
                limit: r.limits.pushNotifications,
                value: r.relationsCount.pushNotifications
            }, null, 8, ["limit", "value"])]), i("dt", null, c(a.$t("Notifications (sent per hour)")), 1), i("dd", null, [v(h, {
                limit: r.limits.pushNotificationsPerHour.limit,
                value: r.limits.pushNotificationsPerHour.limit - r.limits.pushNotificationsPerHour.left
            }, null, 8, ["limit", "value"])])])) : b("", !0)])) : b("", !0), r.currentTab === "data" ? (m(), y("div", Ye, [i("p", null, c(a.$t("Some of the data collected for your account will be automatically cleared after certain period of time. Please find the details below.")), 1), i("table", Je, [i("thead", null, [i("tr", null, [i("th", null, c(a.$t("Data")), 1), i("th", null, c(a.$t("Will be deleted after")), 1)])]), i("tbody", null, [i("tr", null, [i("th", We, c(a.$t("Electricity meter voltage history")), 1), i("td", null, c(a.$t("{days} days", {
                days: a.frontendConfigStore.config.measurementLogsRetention?.em_voltage || 90
            })), 1)]), i("tr", null, [i("th", xe, c(a.$t("Electricity meter voltage aberrations history")), 1), i("td", null, c(a.$t("{days} days", {
                days: a.frontendConfigStore.config.measurementLogsRetention?.em_voltage_aberrations || 180
            })), 1)]), i("tr", null, [i("th", Ze, c(a.$t("Electricity meter current history")), 1), i("td", null, c(a.$t("{days} days", {
                days: a.frontendConfigStore.config.measurementLogsRetention?.em_current || 90
            })), 1)]), i("tr", null, [i("th", Xe, c(a.$t("Electricity meter active power history")), 1), i("td", null, c(a.$t("{days} days", {
                days: a.frontendConfigStore.config.measurementLogsRetention?.em_power_active || 950
            })), 1)])])])])) : b("", !0), r.currentTab === "api" ? (m(), y("div", Qe, [i("div", et, [i("div", tt, [i("div", it, [i("div", at, [v(h, {
                limit: r.apiRateStatus.limit,
                value: r.apiRateStatus.requests
            }, {
                default: E(() => [r.apiRateStatus.seconds === 60 ? (m(), y("span", nt, c(a.$t("{requests} out of {limit} / min", r.apiRateStatus)), 1)) : r.apiRateStatus.seconds === 3600 ? (m(), y("span", rt, c(a.$t("{requests} out of {limit} / h", r.apiRateStatus)), 1)) : r.apiRateStatus.seconds === 86400 ? (m(), y("span", ot, c(a.$t("{requests} out of {limit} / day", r.apiRateStatus)), 1)) : (m(), y("span", st, c(a.$t("{requests} out of {limit} / {seconds} sec.", r.apiRateStatus)), 1))], void 0, !0),
                _: 1
            }, 8, ["limit", "value"])])]), r.apiRateStatus.requests > 0 ? (m(), y("p", lt, c(a.$t("Next limit renewal: {date}", {
                date: A.apiRateStatusReset
            })), 1)) : b("", !0)])])])) : b("", !0), r.currentTab === "other" ? (m(), y("div", ct, [i("p", null, c(a.$t("Your channel groups are allowed to have a maximum of {max} channels.", {
                max: r.limits.channelPerGroup
            })), 1), i("p", null, c(a.$t("Your schedules are allowed to have a maximum of {max} actions.", {
                max: r.limits.actionsPerSchedule
            })), 1), i("p", null, c(a.$t("Your scenes are allowed to have a maximum of {max} operations.", {
                max: r.limits.operationsPerScene
            })), 1)])) : b("", !0), r.currentTab === "edit" ? (m(), y("div", ut, [i("div", att, [i("div", ntt, [i("h4", null, c(a.$t("Feature limits")), 1), (m(!0), y(H, null, U(A.featureLimitFields, t => (m(), y("div", {
                key: t.field,
                class: "form-group"
            }, [i("label", null, c(a.$t(t.label)), 1), t.progressLimit ? (m(), S(h, {
                key: 0,
                limit: t.progressLimit(r.limits),
                value: t.progressValue(r.limits, r.relationsCount)
            }, null, 8, ["limit", "value"])) : b("", !0), N(i("input", {
                "onUpdate:modelValue": o => r.draftLimits[t.field] = o,
                class: "form-control",
                type: "number",
                min: "0",
                step: "1"
            }, null, 8, rtt), [
                [J, r.draftLimits[t.field], void 0, {
                    number: !0
                }]
            ])]))), 128))]), i("div", ott, [i("h4", null, c(a.$t("Other")), 1), (m(!0), y(H, null, U(A.otherLimitFields, t => (m(), y("div", {
                key: t.field,
                class: "form-group"
            }, [i("label", null, c(a.$t(t.label)), 1), N(i("input", {
                "onUpdate:modelValue": o => r.draftLimits[t.field] = o,
                class: "form-control",
                type: "number",
                min: "0",
                step: "1"
            }, null, 8, stt), [
                [J, r.draftLimits[t.field], void 0, {
                    number: !0
                }]
            ])]))), 128)), i("div", ltt, [i("label", null, c(a.$t("API rate limits")), 1), N(i("input", {
                "onUpdate:modelValue": s[5] || (s[5] = t => r.draftApiRateLimit = t),
                class: "form-control",
                type: "text",
                placeholder: "120/60"
            }, null, 512), [
                [J, r.draftApiRateLimit, void 0, {
                    trim: !0
                }]
            ]), s[10] || (s[10] = i("p", {
                class: "help-block"
            }, "Leave empty to use the default limit.", -1))])])])])) : b("", !0)])) : b("", !0)], void 0, !0),
            _: 1
        }, 8, ["loading"]), !A.canEditLimits && !a.frontendConfigStore.config.actAsBrokerCloud && r.currentTab !== "data" ? (m(), y("div", ctt, [i("p", utt, [v(e, {
            keypath: "Use the {0} server command to change this account's limits. For example:"
        }, {
            default: E(() => [...s[11] || (s[11] = [i("code", null, "supla:user:change-limits", -1)])], void 0, !0),
            _: 1
        })]), i("pre", null, [i("code", null, "docker compose exec -u www-data supla-cloud php bin/console supla:user:change-limits " + c(f.user.email), 1)])])) : b("", !0)], void 0, !0),
        _: 1
    }, 8, ["header", "onConfirm", "onCancel"])
}
const dt = P($e, [
        ["render", ftt]
    ]),
    mt = {
        components: {
            LoadingCover: $,
            AccountLimitsModal: dt,
            AccountDeleteModal: Be,
            TimezonePicker: pe,
            AnimatedSvg: ue,
            AccountOptOutNotificationsModal: Pe
        },
        data() {
            return {
                user: void 0,
                animationFinished: !1,
                deletingAccount: !1,
                showingLimits: !1,
                changingNotifications: !1
            }
        },
        mounted() {
            setTimeout(() => this.animationFinished = !0, 2e3), M.get("users/current").then(a => {
                this.user = a.body
            }), this.$route.query.optOutNotification && (this.changingNotifications = !0)
        },
        methods: {
            withBaseUrl: ae,
            closeOptOutNotificationsModal() {
                this.changingNotifications = !1, this.$route.query.optOutNotification && this.$router.push({
                    optOutNotification: void 0
                })
            }
        },
        computed: {
            suplaServerHost() {
                return this.frontendConfig.suplaUrl.replace(/https?:\/\//, "")
            },
            ...V(I, {
                frontendConfig: "config",
                frontendVersion: "frontendVersion"
            })
        }
    },
    ht = {
        class: "account-page"
    },
    ft = {
        class: "supla-version"
    },
    pt = {
        key: 0,
        class: "user-account"
    },
    At = {
        class: "no-margin"
    },
    gt = {
        class: "form-group text-center"
    },
    yt = {
        class: "text-center"
    },
    vt = {
        key: 0
    };

function wt(a, s, f, w, r, A) {
    const h = _("animated-svg"),
        u = _("timezone-picker"),
        e = _("loading-cover"),
        n = _("account-opt-out-notifications-modal"),
        t = _("account-limits-modal"),
        o = _("account-delete-modal"),
        l = Q("title");
    return N((m(), y("div", ht, [v(h, {
        id: "user-account-bg",
        file: A.withBaseUrl("assets/img/user-account-bg.svg", !1)
    }, null, 8, ["file"]), i("div", {
        class: C("user-account-container " + (r.animationFinished ? "animation-finished" : ""))
    }, [v(e, {
        loading: !r.user
    }, {
        default: E(() => [i("span", ft, "supla cloud " + c(a.frontendVersion), 1), v(ee, {
            name: "fade"
        }, {
            default: E(() => [r.user ? (m(), y("div", pt, [i("h1", null, c(r.user.email), 1), i("dl", At, [i("dt", null, c(a.$t("Server address")), 1), i("dd", null, c(A.suplaServerHost), 1)]), i("dl", null, [i("dt", null, c(a.$t("Timezone")), 1), i("dd", null, [v(u, {
                timezone: r.user.timezone
            }, null, 8, ["timezone"])])]), i("div", gt, [i("a", {
                class: "btn btn-default",
                onClick: s[0] || (s[0] = d => r.changingNotifications = !0)
            }, c(a.$t("Account notifications")), 1), i("a", {
                class: "btn btn-default",
                onClick: s[1] || (s[1] = d => r.showingLimits = !0)
            }, c(a.$t("Show my limits")), 1)]), i("div", yt, [i("a", {
                class: "btn btn-red-outline btn-xs",
                onClick: s[2] || (s[2] = d => r.deletingAccount = !0)
            }, c(a.$t("Delete my account")), 1)])])) : b("", !0)], void 0, !0),
            _: 1
        })], void 0, !0),
        _: 1
    }, 8, ["loading"])], 2), r.user ? (m(), y("div", vt, [r.changingNotifications ? (m(), S(n, {
        key: 0,
        user: r.user,
        onCancel: s[3] || (s[3] = d => A.closeOptOutNotificationsModal())
    }, null, 8, ["user"])) : b("", !0), r.showingLimits ? (m(), S(t, {
        key: 1,
        user: r.user,
        onConfirm: s[4] || (s[4] = d => r.showingLimits = !1)
    }, null, 8, ["user"])) : b("", !0), r.deletingAccount ? (m(), S(o, {
        key: 2,
        user: r.user,
        onCancel: s[5] || (s[5] = d => r.deletingAccount = !1)
    }, null, 8, ["user"])) : b("", !0)])) : b("", !0)])), [
        [l, a.$t("Account")]
    ])
}
const St = P(mt, [
    ["render", wt]
]);
export {
    St as
    default
};
