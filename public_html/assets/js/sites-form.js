(function () {
  'use strict';

  var MULTI_TLDS = {
    'co.uk': 1, 'org.uk': 1, 'me.uk': 1, 'ac.uk': 1, 'gov.uk': 1, 'ltd.uk': 1, 'plc.uk': 1, 'net.uk': 1,
    'com.au': 1, 'net.au': 1, 'org.au': 1, 'edu.au': 1, 'gov.au': 1, 'asn.au': 1, 'id.au': 1,
    'co.nz': 1, 'org.nz': 1, 'net.nz': 1, 'govt.nz': 1, 'ac.nz': 1,
    'co.za': 1, 'org.za': 1, 'web.za': 1, 'net.za': 1,
    'com.br': 1, 'net.br': 1, 'org.br': 1, 'gov.br': 1,
    'co.jp': 1, 'or.jp': 1, 'ne.jp': 1, 'ac.jp': 1, 'go.jp': 1,
    'com.mx': 1, 'org.mx': 1, 'gob.mx': 1,
    'com.sg': 1, 'com.hk': 1, 'com.tw': 1, 'com.tr': 1, 'com.my': 1, 'com.ph': 1,
    'co.in': 1, 'firm.in': 1, 'gen.in': 1, 'ind.in': 1, 'net.in': 1, 'org.in': 1,
    'com.ar': 1, 'com.co': 1, 'com.pe': 1, 'com.ve': 1, 'com.ec': 1,
    'co.kr': 1, 'co.th': 1, 'co.il': 1, 'org.il': 1, 'ac.il': 1,
    'com.cn': 1, 'net.cn': 1, 'org.cn': 1,
    'co.id': 1, 'or.id': 1, 'web.id': 1,
    'com.pl': 1, 'net.pl': 1, 'org.pl': 1, 'info.pl': 1, 'biz.pl': 1, 'edu.pl': 1, 'gov.pl': 1,
    'com.pk': 1, 'net.pk': 1, 'org.pk': 1, 'gov.pk': 1, 'edu.pk': 1,
    'com.ua': 1, 'net.ua': 1, 'org.ua': 1, 'gov.ua': 1,
    'com.pt': 1, 'net.pt': 1, 'org.pt': 1, 'gov.pt': 1, 'edu.pt': 1, 'publ.pt': 1,
    'com.es': 1, 'nom.es': 1, 'org.es': 1, 'gob.es': 1, 'edu.es': 1,
    'com.ng': 1, 'org.ng': 1, 'gov.ng': 1, 'edu.ng': 1, 'net.ng': 1,
    'com.eg': 1, 'net.eg': 1, 'org.eg': 1, 'edu.eg': 1, 'gov.eg': 1,
    'com.sa': 1, 'net.sa': 1, 'org.sa': 1, 'edu.sa': 1, 'gov.sa': 1,
    'com.bd': 1, 'net.bd': 1, 'org.bd': 1, 'edu.bd': 1, 'gov.bd': 1, 'ac.bd': 1,
    'com.np': 1, 'net.np': 1, 'org.np': 1, 'edu.np': 1, 'gov.np': 1,
    'com.lk': 1, 'org.lk': 1, 'edu.lk': 1, 'gov.lk': 1, 'net.lk': 1,
    'com.kh': 1, 'net.kh': 1, 'org.kh': 1, 'edu.kh': 1, 'gov.kh': 1,
    'co.ke': 1, 'or.ke': 1, 'ne.ke': 1, 'go.ke': 1, 'ac.ke': 1,
    'com.cy': 1, 'net.cy': 1, 'org.cy': 1, 'ac.cy': 1, 'gov.cy': 1,
    'com.mt': 1, 'org.mt': 1, 'net.mt': 1, 'edu.mt': 1, 'gov.mt': 1,
    'com.ro': 1, 'org.ro': 1,
    'com.gr': 1, 'net.gr': 1, 'org.gr': 1, 'edu.gr': 1, 'gov.gr': 1,
    'com.hr': 1, 'from.hr': 1, 'iz.hr': 1, 'name.hr': 1,
    'com.ba': 1, 'net.ba': 1, 'org.ba': 1, 'edu.ba': 1, 'gov.ba': 1,
    'co.ao': 1, 'it.ao': 1, 'og.ao': 1, 'pb.ao': 1, 'gv.ao': 1,
    'co.bw': 1, 'org.bw': 1,
    'co.ug': 1, 'or.ug': 1, 'ac.ug': 1, 'go.ug': 1, 'ne.ug': 1, 'sc.ug': 1,
    'co.tz': 1, 'or.tz': 1, 'ac.tz': 1, 'go.tz': 1, 'ne.tz': 1, 'sc.tz': 1,
    'co.zm': 1, 'org.zm': 1,
    'co.zw': 1, 'org.zw': 1, 'ac.zw': 1, 'gov.zw': 1
  };

  // Second-level labels commonly paired with a 2-letter country code.
  var COUNTRY_SLD = {
    com: 1, co: 1, org: 1, net: 1, gov: 1, edu: 1, ac: 1, gob: 1, go: 1, or: 1, ne: 1,
    me: 1, ltd: 1, plc: 1, gen: 1, firm: 1, ind: 1, web: 1, asn: 1, id: 1, info: 1,
    biz: 1, name: 1, nom: 1, publ: 1, from: 1, iz: 1, it: 1, og: 1, pb: 1, gv: 1,
    sc: 1, govt: 1
  };

  // Valid TLDs from the IANA root zone (ccTLDs + gTLDs). Accepts .gal / .madrid; rejects .comz
  var VALID_TLDS = {};
  (function buildValidTlds() {
    var iana = 'aaa aarp abarth abb abbott abbvie abc able abogado abudhabi ac academy accenture accountant'
      + ' accountants aco active actor ad adac ads adult ae aeg aero aetna af afamilycompany afl africa ag'
      + ' agakhan agency ai aig aigo airbus airforce airtel akdn al alfaromeo alibaba alipay allfinanz'
      + ' allstate ally alsace alstom am amazon americanexpress americanfamily amex amfam amica amsterdam'
      + ' an analytics android anquan anz ao aol apartments app apple aq aquarelle ar arab aramco archi'
      + ' army arpa art arte as asda asia associates at athleta attorney au auction audi audible audio'
      + ' auspost author auto autos avianca aw aws ax axa az azure ba baby baidu banamex bananarepublic'
      + ' band bank bar barcelona barclaycard barclays barefoot bargains baseball basketball bauhaus'
      + ' bayern bb bbc bbt bbva bcg bcn bd be beats beauty beer bentley berlin best bestbuy bet bf bg bh'
      + ' bharti bi bible bid bike bing bingo bio biz bj bl black blackfriday blanco blockbuster blog'
      + ' bloomberg blue bm bms bmw bn bnl bnpparibas bo boats boehringer bofa bom bond boo book booking'
      + ' boots bosch bostik boston bot boutique box bq br bradesco bridgestone broadway broker brother'
      + ' brussels bs bt budapest bugatti build builders business buy buzz bv bw by bz bzh ca cab cafe cal'
      + ' call calvinklein cam camera camp cancerresearch canon capetown capital capitalone car caravan'
      + ' cards care career careers cars cartier casa case caseih cash casino cat catering catholic cba'
      + ' cbn cbre cbs cc cd ceb center ceo cern cf cfa cfd cg ch chanel channel charity chase chat cheap'
      + ' chintai chloe christmas chrome chrysler church ci cipriani circle cisco citadel citi citic city'
      + ' cityeats ck cl claims cleaning click clinic clinique clothing cloud club clubmed cm cn co coach'
      + ' codes coffee college cologne com comcast commbank community company compare computer comsec'
      + ' condos construction consulting contact contractors cooking cookingchannel cool coop corsica'
      + ' country coupon coupons courses cpa cr credit creditcard creditunion cricket crown crs cruise'
      + ' cruises csc cu cuisinella cv cw cx cy cymru cyou cz dabur dad dance data date dating datsun day'
      + ' dclk dds de deal dealer deals degree delivery dell deloitte delta democrat dental dentist desi'
      + ' design dev dhl diamonds diet digital direct directory discount discover dish diy dj dk dm dnp do'
      + ' docs doctor dodge dog doha domains doosan dot download drive dtv dubai duck dunlop duns dupont'
      + ' durban dvag dvr dz earth eat ec eco edeka edu education ee eg eh email emerck emerson energy'
      + ' engineer engineering enterprises epost epson equipment er ericsson erni es esq estate esurance'
      + ' et etisalat eu eurovision eus events everbank exchange expert exposed express extraspace fage'
      + ' fail fairwinds faith family fan fans farm farmers fashion fast fedex feedback ferrari ferrero fi'
      + ' fiat fidelity fido film final finance financial fire firestone firmdale fish fishing fit fitness'
      + ' fj fk flickr flights flir florist flowers flsmidth fly fm fo foo food foodnetwork football ford'
      + ' forex forsale forum foundation fox fr free fresenius frl frogans frontdoor frontier ftr fujitsu'
      + ' fujixerox fun fund furniture futbol fyi ga gal gallery gallo gallup game games gap garden gay gb'
      + ' gbiz gd gdn ge gea gent genting george gf gg ggee gh gi gift gifts gives giving gl glade glass'
      + ' gle global globo gm gmail gmbh gmo gmx gn godaddy gold goldpoint golf goo goodhands goodyear'
      + ' goog google gop got gov gp gq gr grainger graphics gratis green gripe grocery group gs gt gu'
      + ' guardian gucci guge guide guitars guru gw gy hair hamburg hangout haus hbo hdfc hdfcbank health'
      + ' healthcare help helsinki here hermes hgtv hiphop hisamitsu hitachi hiv hk hkt hm hn hockey'
      + ' holdings holiday homedepot homegoods homes homesense honda honeywell horse hospital host hosting'
      + ' hot hoteles hotels hotmail house how hr hsbc ht htc hu hughes hyatt hyundai ibm icbc ice icu id'
      + ' ie ieee ifm iinet ikano il im imamat imdb immo immobilien in inc industries infiniti info ing'
      + ' ink institute insurance insure int intel international intuit investments io ipiranga iq ir'
      + ' irish is iselect ismaili ist istanbul it itau itv iveco iwc jaguar java jcb jcp je jeep jetzt'
      + ' jewelry jio jlc jll jm jmp jnj jo jobs joburg jot joy jp jpmorgan jprs juegos juniper kaufen'
      + ' kddi ke kerryhotels kerrylogistics kerryproperties kfh kg kh ki kia kids kim kinder kindle'
      + ' kitchen kiwi km kn koeln komatsu kosher kp kpmg kpn kr krd kred kuokgroup kw ky kyoto kz la'
      + ' lacaixa ladbrokes lamborghini lamer lancaster lancia lancome land landrover lanxess lasalle lat'
      + ' latino latrobe law lawyer lb lc lds lease leclerc lefrak legal lego lexus lgbt li liaison lidl'
      + ' life lifeinsurance lifestyle lighting like lilly limited limo lincoln linde link lipsy live'
      + ' living lixil lk llc llp loan loans locker locus loft lol london lotte lotto love lpl'
      + ' lplfinancial lr ls lt ltd ltda lu lundbeck lupin luxe luxury lv ly ma macys madrid maif maison'
      + ' makeup man management mango map market marketing markets marriott marshalls maserati mattel mba'
      + ' mc mcd mcdonalds mckinsey md me med media meet melbourne meme memorial men menu meo merck'
      + ' merckmsd metlife mf mg mh miami microsoft mil mini mint mit mitsubishi mk ml mlb mls mm mma mn'
      + ' mo mobi mobile mobily moda moe moi mom monash money monster montblanc mopar mormon mortgage'
      + ' moscow moto motorcycles mov movie movistar mp mq mr ms msd mt mtn mtpc mtr mu museum music'
      + ' mutual mutuelle mv mw mx my mz na nab nadex nagoya name nationwide natura navy nba nc ne nec net'
      + ' netbank netflix network neustar new newholland news next nextdirect nexus nf nfl ng ngo nhk ni'
      + ' nico nike nikon ninja nissan nissay nl no nokia northwesternmutual norton now nowruz nowtv np nr'
      + ' nra nrw ntt nu nyc nz obi observer off office okinawa olayan olayangroup oldnavy ollo om omega'
      + ' one ong onl online onyourside ooo open oracle orange org organic orientexpress origins osaka'
      + ' otsuka ott ovh pa page pamperedchef panasonic panerai paris pars partners parts party passagens'
      + ' pay pccw pe pet pf pfizer pg ph pharmacy phd philips phone photo photography photos physio'
      + ' piaget pics pictet pictures pid pin ping pink pioneer pizza pk pl place play playstation'
      + ' plumbing plus pm pn pnc pohl poker politie porn post pr pramerica praxi press prime pro prod'
      + ' productions prof progressive promo properties property protection pru prudential ps pt pub pw'
      + ' pwc py qa qpon quebec quest qvc racing radio raid re read realestate realtor realty recipes red'
      + ' redstone redumbrella rehab reise reisen reit reliance ren rent rentals repair report republican'
      + ' rest restaurant review reviews rexroth rich richardli ricoh rightathome ril rio rip rmit ro'
      + ' rocher rocks rodeo rogers room rs rsvp ru rugby ruhr run rw rwe ryukyu sa saarland safe safety'
      + ' sakura sale salon samsclub samsung sandvik sandvikcoromant sanofi sap sapo sarl sas save saxo sb'
      + ' sbi sbs sc sca scb schaeffler schmidt scholarships school schule schwarz science scjohnson scor'
      + ' scot sd se search seat secure security seek select sener services ses seven sew sex sexy sfr sg'
      + ' sh shangrila sharp shaw shell shia shiksha shoes shop shopping shouji show showtime shriram si'
      + ' silk sina singles site sj sk ski skin sky skype sl sling sm smart smile sn sncf so soccer social'
      + ' softbank software sohu solar solutions song sony soy spa space spiegel sport spot spreadbetting'
      + ' sr srl srt ss st stada staples star starhub statebank statefarm statoil stc stcgroup stockholm'
      + ' storage store stream studio study style su sucks supplies supply support surf surgery suzuki sv'
      + ' swatch swiftcover swiss sx sy sydney symantec systems sz tab taipei talk taobao target'
      + ' tatamotors tatar tattoo tax taxi tc tci td tdk team tech technology tel telecity telefonica'
      + ' temasek tennis teva tf tg th thd theater theatre tiaa tickets tienda tiffany tips tires tirol tj'
      + ' tjmaxx tjx tk tkmaxx tl tm tmall tn to today tokyo tools top toray toshiba total tours town'
      + ' toyota toys tp tr trade trading training travel travelchannel travelers travelersinsurance trust'
      + ' trv tt tube tui tunes tushu tv tvs tw tz ua ubank ubs uconnect ug uk um unicom university uno'
      + ' uol ups us uy uz va vacations vana vanguard vc ve vegas ventures verisign versicherung vet vg vi'
      + ' viajes video vig viking villas vin vip virgin visa vision vista vistaprint viva vivo vlaanderen'
      + ' vn vodka volkswagen volvo vote voting voto voyage vu vuelos wales walmart walter wang wanggou'
      + ' warman watch watches weather weatherchannel web webcam weber website wed wedding weibo weir wf'
      + ' whoswho wien wiki williamhill win windows wine winners wme wolterskluwer woodside work works'
      + ' world wow ws wtc wtf xbox xerox xfinity xihuan xin xperia xxx xyz yachts yahoo yamaxun yandex ye'
      + ' yodobashi yoga yokohama you youtube yt yun za zappos zara zero zip zippo zm zone zuerich zw xk';
    iana.split(/\s+/).forEach(function (t) {
      if (t) VALID_TLDS[t] = 1;
    });
  })();

  function isKnownTld(tld) {
    return !!(tld && VALID_TLDS[String(tld).toLowerCase()]);
  }

  /** Platform hosts that look like TLDs but must keep the tenant label (utilfox.vercel.app). */
  var PLATFORM_PUBLIC_SUFFIXES = {
    'vercel.app': 1,
    'github.io': 1,
    'herokuapp.com': 1,
    'netlify.app': 1,
    'pages.dev': 1,
    'workers.dev': 1,
    'web.app': 1,
    'firebaseapp.com': 1,
    'azurewebsites.net': 1,
    'myshopify.com': 1,
    'blogspot.com': 1,
    'wordpress.com': 1,
    'tumblr.com': 1,
    'gitlab.io': 1
  };

  function isKnownPublicSuffix(suffix) {
    suffix = String(suffix || '').toLowerCase();
    if (!suffix) return false;
    if (MULTI_TLDS[suffix]) return true;
    if (PLATFORM_PUBLIC_SUFFIXES[suffix]) return true;
    if (suffix.indexOf('.') === -1) return isKnownTld(suffix);
    var parts = suffix.split('.').filter(Boolean);
    if (parts.length !== 2) return false;
    return isKnownTld(parts[1]) && !!COUNTRY_SLD[parts[0]];
  }

  function publicSuffix(host) {
    var parts = host.split('.').filter(Boolean);
    if (parts.length < 2) return '';
    var two = parts[parts.length - 2] + '.' + parts[parts.length - 1];
    if (PLATFORM_PUBLIC_SUFFIXES[two]) return two;
    if (MULTI_TLDS[two]) return two;
    // Heuristic: keep multi-part country suffixes (com.pl, com.pk, co.uk, …)
    var sld = parts[parts.length - 2];
    var cc = parts[parts.length - 1];
    if (cc.length === 2 && isKnownTld(cc) && COUNTRY_SLD[sld]) {
      return two;
    }
    return parts[parts.length - 1];
  }

  function isRootDomain(host) {
    if (!host || host.indexOf('.') === -1) return false;
    if (!/^[a-z0-9.-]+$/.test(host)) return false;
    if (host.charAt(0) === '-' || host.slice(-1) === '-' || host.indexOf('..') !== -1) return false;
    var parts = host.split('.').filter(Boolean);
    if (parts.length < 2) return false;
    for (var i = 0; i < parts.length; i++) {
      var label = parts[i];
      if (!label || label.length > 63) return false;
      if (!/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(label)) return false;
    }
    var suffix = publicSuffix(host);
    if (!suffix || !isKnownPublicSuffix(suffix)) return false;
    var suffixParts = suffix.split('.').length;
    return (parts.length - suffixParts) === 1;
  }

  /**
   * Pull a hostname out of a messy paste (https, path, port, www, user@host,
   * markdown links, href=, Excel tabs, attention-box "# reason" tags).
   */
  function extractHostCandidate(raw) {
    var s = String(raw || '').trim();
    if (!s) return '';
    // Strip attention-box reason tags: "junk  # has_spaces"
    var attMatch = s.match(/^(.*)\s+#\s+[a-z0-9_]+\s*$/i);
    if (attMatch) s = String(attMatch[1] || '').trim();
    // Markdown link: [text](https://example.com/x)
    var mdMatch = s.match(/\[[^\]]*\]\((https?:\/\/[^)\s]+)\)/i);
    if (mdMatch) {
      s = mdMatch[1];
    } else {
      var hrefMatch = s.match(/href\s*=\s*["']\s*(https?:\/\/[^"']+)["']/i);
      if (hrefMatch) {
        s = hrefMatch[1];
      } else {
        var urlInJunk = s.match(/(https?:\/\/[^\s<>"']+)/i);
        if (urlInJunk) s = urlInJunk[1];
      }
    }
    // Excel-style "domain\tnotes" — keep first column
    if (s.indexOf('\t') !== -1) {
      var firstCol = s.split('\t')[0].trim();
      if (firstCol) s = firstCol;
    }
    s = s.replace(/^[\s'"\[<\(]+/, '').replace(/[\s'"\]>\)]+$/, '');
    if (!s) return '';
    // Prefer URL parser when the browser can read the token (handles https://…/path?#…).
    try {
      var probe = s;
      if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(probe) && probe.indexOf('.') !== -1) {
        // Bare host or host/path without scheme
        if (/^[a-z0-9.-]+(\/|\?|#|$)/i.test(probe)) {
          probe = 'https://' + probe;
        }
      }
      // Typo schemes: ttps://, htps://, ttp://
      probe = probe.replace(/^(?:h?ttps?|tps?):\/\//i, 'https://');
      var u = new URL(probe);
      if (u.hostname) {
        s = u.hostname;
      }
    } catch (e) {
      s = s.replace(/^[a-z][a-z0-9+.-]*:\/\//i, '');
      if (s.indexOf('//') === 0) s = s.slice(2);
      s = s.split('/')[0].split('?')[0].split('#')[0];
    }
    if (s.indexOf('@') !== -1) {
      s = s.split('@').pop() || '';
    }
    s = String(s).toLowerCase();
    if (s.indexOf(':') !== -1 && s.indexOf(']') === -1) {
      s = s.split(':')[0];
    }
    s = s.replace(/^www\./i, '').replace(/\.$/, '');
    return s;
  }

  /**
   * Reduce host to apex/root domain (eTLD+1), e.g. blog.example.co.uk → example.co.uk
   */
  function toRootDomain(host) {
    host = String(host || '').toLowerCase().replace(/^www\./, '').replace(/\.$/, '');
    if (!host || host.indexOf('.') === -1) return '';
    if (!/^[a-z0-9.-]+$/.test(host)) return '';
    if (host.charAt(0) === '-' || host.slice(-1) === '-' || host.indexOf('..') !== -1) return '';
    var parts = host.split('.').filter(Boolean);
    if (parts.length < 2) return '';
    for (var i = 0; i < parts.length; i++) {
      var label = parts[i];
      if (!label || label.length > 63) return '';
      if (!/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/.test(label)) return '';
    }
    var suffix = publicSuffix(host);
    if (!suffix) return '';
    var suffixParts = suffix.split('.').length;
    var need = suffixParts + 1;
    if (parts.length < need) return '';
    var root = parts.slice(parts.length - need).join('.');
    return isRootDomain(root) ? root : '';
  }

  /**
   * Try to correct one pasted token into a root domain (does not delete — returns '').
   */
  function repairLine(line) {
    var raw = String(line || '').trim();
    if (!raw) return { ok: false, domain: '', reason: 'empty', raw: raw, fixed: false };
    var host = extractHostCandidate(raw);
    var root = toRootDomain(host);
    if (root) {
      var alreadyClean = (raw.toLowerCase() === root);
      return { ok: true, domain: root, reason: '', raw: raw, fixed: !alreadyClean };
    }
    if (/https?:\/\//i.test(raw) || raw.indexOf('://') !== -1 || raw.indexOf('//') === 0) {
      return { ok: false, domain: '', reason: 'has_scheme', raw: raw, fixed: false };
    }
    if (raw.indexOf('/') !== -1 || raw.indexOf('?') !== -1 || raw.indexOf('#') !== -1) {
      return { ok: false, domain: '', reason: 'has_path', raw: raw, fixed: false };
    }
    if (/\s/.test(raw)) {
      return { ok: false, domain: '', reason: 'has_spaces', raw: raw, fixed: false };
    }
    if (host && host.indexOf('.') !== -1) {
      var suffix = publicSuffix(host);
      var suffixParts = suffix ? suffix.split('.').length : 1;
      var parts = host.split('.').filter(Boolean);
      if (parts.length - suffixParts > 1) {
        return { ok: false, domain: '', reason: 'subdomain', raw: raw, fixed: false };
      }
    }
    return { ok: false, domain: '', reason: 'invalid', raw: raw, fixed: false };
  }

  function analyzeLine(line) {
    return repairLine(line);
  }

  /** Split a line into domain-like chunks (commas and/or whitespace). */
  function splitChunks(line) {
    var s = String(line || '').trim();
    if (!s) return [];
    if (s.indexOf(',') !== -1) {
      return s.split(/\s*,\s*/).map(function (c) { return c.trim(); }).filter(Boolean);
    }
    // Multiple URL/domain tokens on one line
    if (/\s/.test(s) && (/https?:\/\//i.test(s) || /\/\/|\//.test(s) || s.split(/\s+/).length > 1)) {
      var parts = s.split(/\s+/).filter(Boolean);
      if (parts.length > 1) return parts;
    }
    return [s];
  }

  function parseDomains(raw) {
    var text = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    var lines = text.split(/\n+/);
    var validMap = {};
    var validOrder = [];
    var invalid = [];
    var fixed = 0;
    var dirty = 0;
    lines.forEach(function (line) {
      line = line.trim();
      if (!line) return;
      splitChunks(line).forEach(function (chunk) {
        var a = analyzeLine(chunk);
        if (a.ok) {
          if (a.fixed) {
            fixed++;
            dirty++;
          }
          if (!validMap[a.domain]) {
            validMap[a.domain] = true;
            validOrder.push(a.domain);
          }
        } else if (a.reason !== 'empty') {
          invalid.push(a);
        }
      });
    });
    return {
      valid: validOrder,
      invalid: invalid,
      validText: validOrder.join('\n'),
      fixed: fixed,
      dirty: dirty
    };
  }

  /**
   * Clean = Ready roots + Needs attention (unfixable). Never mixes bad lines into Ready.
   */
  function cleanDomains(raw) {
    var text = String(raw || '').replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    var lines = text.split(/\n+/);
    var validMap = {};
    var ready = [];
    var attention = [];
    var fixed = 0;
    lines.forEach(function (line) {
      line = line.trim();
      if (!line) return;
      var chunks = splitChunks(line);
      chunks.forEach(function (chunk) {
        var a = repairLine(chunk);
        if (a.ok) {
          if (a.fixed) fixed++;
          if (!validMap[a.domain]) {
            validMap[a.domain] = true;
            ready.push(a.domain);
          }
        } else if (a.reason !== 'empty') {
          attention.push(a.raw + (a.reason ? ('  # ' + a.reason) : ''));
        }
      });
    });
    return {
      text: ready.join('\n'),
      readyText: ready.join('\n'),
      attentionText: attention.join('\n'),
      fixed: fixed,
      keptBad: attention.length,
      valid: ready.slice(),
      attention: attention
    };
  }

  function applyCleanToTextarea(ta, status, root) {
    if (!ta) return null;
    var wrap = root || (ta.closest && ta.closest('[data-domains-paste]')) || null;
    var attentionTa = wrap ? wrap.querySelector('[data-domains-attention]') : null;
    var attentionWrap = wrap ? wrap.querySelector('[data-domains-attention-wrap]') : null;
    var before = ta.value;
    // If attention box already has lines, include them in the clean pass so edits re-process.
    var combined = before;
    if (attentionTa && String(attentionTa.value || '').trim()) {
      combined = before + (before && !/\n$/.test(before) ? '\n' : '') + attentionTa.value;
    }
    var cleaned = cleanDomains(combined);
    ta.value = cleaned.readyText;
    if (attentionTa) {
      attentionTa.value = cleaned.attentionText;
    }
    if (attentionWrap) {
      attentionWrap.hidden = cleaned.keptBad < 1;
    }
    try {
      ta.dispatchEvent(new Event('input', { bubbles: true }));
      ta.dispatchEvent(new Event('change', { bubbles: true }));
    } catch (e) { /* ignore */ }
    if (status) {
      if (cleaned.keptBad > 0) {
        status.hidden = false;
        status.classList.add('domains-paste-warn');
        status.textContent = 'Ready: ' + cleaned.valid.length
          + ' · corrected ' + cleaned.fixed
          + ' · ' + cleaned.keptBad + ' need attention (listed below). Push uses Ready only.';
      } else if (cleaned.fixed > 0 || cleaned.readyText !== before) {
        status.hidden = false;
        status.classList.remove('domains-paste-warn');
        status.textContent = cleaned.fixed > 0
          ? ('Ready: ' + cleaned.valid.length + ' root domain'
            + (cleaned.valid.length === 1 ? '' : 's')
            + ' (corrected ' + cleaned.fixed + ').')
          : 'Already root domains — nothing to clean.';
      } else if (cleaned.valid.length > 0) {
        status.hidden = false;
        status.classList.remove('domains-paste-warn');
        status.textContent = 'Already root domains — nothing to clean.';
      } else {
        status.hidden = true;
        status.textContent = '';
        status.classList.remove('domains-paste-warn');
      }
    }
    return cleaned;
  }

  function initDomainsPaste(root) {
    var ta = root.querySelector('[data-domains-input]');
    var status = root.querySelector('[data-domains-status]');
    var attentionTa = root.querySelector('[data-domains-attention]');
    var attentionWrap = root.querySelector('[data-domains-attention-wrap]');
    if (!ta) return;

    function updateStatus() {
      var parsed = parseDomains(ta.value);
      if (!status) return;
      if (parsed.invalid.length > 0) {
        status.hidden = false;
        status.classList.add('domains-paste-warn');
        status.textContent = parsed.invalid.length + ' line'
          + (parsed.invalid.length === 1 ? '' : 's')
          + ' need fixing — click Clean to root domains (Ready list vs Needs attention).';
        return;
      }
      if (parsed.dirty > 0) {
        status.hidden = false;
        status.classList.add('domains-paste-warn');
        status.textContent = parsed.dirty + ' line'
          + (parsed.dirty === 1 ? '' : 's')
          + ' still have https/paths/subdomains — click Clean to root domains.';
        return;
      }
      status.hidden = true;
      status.textContent = '';
      status.classList.remove('domains-paste-warn');
    }

    var statusTimer = null;
    function scheduleStatus() {
      if (statusTimer) window.clearTimeout(statusTimer);
      statusTimer = window.setTimeout(function () {
        statusTimer = null;
        updateStatus();
      }, 120);
    }

    ta.addEventListener('input', scheduleStatus);
    ta.addEventListener('paste', function () {
      setTimeout(updateStatus, 0);
    });
    updateStatus();

    var form = ta.closest('form');
    if (form && !form.__domainsPasteGuard) {
      form.__domainsPasteGuard = true;
      form.addEventListener('submit', function (e) {
        var blocks = form.querySelectorAll('[data-domains-paste] [data-domains-input]');
        for (var i = 0; i < blocks.length; i++) {
          var field = blocks[i];
          var wrap = field.closest('[data-domains-paste]');
          var st = wrap && wrap.querySelector('[data-domains-status]');
          var att = wrap && wrap.querySelector('[data-domains-attention]');
          var attWrap = wrap && wrap.querySelector('[data-domains-attention-wrap]');
          var cleaned = cleanDomains(
            field.value + ((att && String(att.value || '').trim())
              ? ('\n' + att.value)
              : '')
          );
          field.value = cleaned.readyText;
          if (att) att.value = cleaned.attentionText;
          if (attWrap) attWrap.hidden = cleaned.keptBad < 1;
          try {
            field.dispatchEvent(new Event('input', { bubbles: true }));
          } catch (err) { /* ignore */ }
          if (!cleaned.valid.length) {
            e.preventDefault();
            field.focus();
            if (st) {
              st.hidden = false;
              st.classList.add('domains-paste-warn');
              st.textContent = cleaned.keptBad > 0
                ? 'No Ready domains — fix Needs attention below, then Clean again.'
                : 'Paste at least one root domain.';
            }
            return;
          }
          // Push uses Ready only; attention may remain as a warning.
          if (cleaned.keptBad > 0 && st) {
            st.hidden = false;
            st.classList.add('domains-paste-warn');
            st.textContent = 'Submitting ' + cleaned.valid.length
              + ' Ready domain' + (cleaned.valid.length === 1 ? '' : 's')
              + ' · ' + cleaned.keptBad + ' left in Needs attention.';
          }
          field.value = cleaned.readyText;
        }
      });
    }
  }

  // Global delegation: Clean still works if a paste block was added later.
  if (typeof window !== 'undefined' && typeof document !== 'undefined'
      && !window.__TXF_DOMAINS_CLEAN_DELEGATE__) {
    window.__TXF_DOMAINS_CLEAN_DELEGATE__ = true;
    document.addEventListener('click', function (e) {
      var btn = e.target && e.target.closest ? e.target.closest('[data-clean-domains]') : null;
      if (!btn) return;
      var root = btn.closest('[data-domains-paste]');
      if (!root) return;
      var ta = root.querySelector('[data-domains-input]');
      var status = root.querySelector('[data-domains-status]');
      if (!ta) return;
      e.preventDefault();
      applyCleanToTextarea(ta, status, root);
      ta.focus();
    });
  }

  function norm(s) {
    return String(s || '').toLowerCase().trim();
  }

  function filterItems(items, q) {
    q = norm(q);
    if (!q) return items.slice(0, 40);
    var starts = [];
    var contains = [];
    items.forEach(function (it) {
      var label = norm(it.label || it.value);
      var value = norm(it.value || '');
      var region = norm(it.region || '');
      var lang = norm(it.lang || '');
      // Match country name / region / default language quietly — do not show
      // language in the selected field (avoids "German + Germany" confusion).
      var hay = label + ' ' + value + ' ' + region + ' ' + lang;
      if (value.indexOf(q) === 0 || label.indexOf(q) === 0 || lang.indexOf(q) === 0) {
        starts.push(it);
      } else if (hay.indexOf(q) !== -1) {
        contains.push(it);
      }
    });
    return starts.concat(contains).slice(0, 40);
  }

  function initTypeahead(root) {
    var input = root.querySelector('[data-typeahead-input]');
    var hidden = root.querySelector('[data-typeahead-value]');
    var list = root.querySelector('[data-typeahead-list]');
    var jsonEl = root.querySelector('[data-typeahead-items]');
    if (!input || !hidden || !list || !jsonEl) return;

    var items = [];
    try {
      items = JSON.parse(jsonEl.textContent || '[]') || [];
    } catch (e) {
      items = [];
    }

    var open = false;
    var active = -1;
    var filtered = [];
    var required = root.getAttribute('data-required') === '1';
    var fillLangSel = root.getAttribute('data-fill-language') || '';
    var fillRegionSel = root.getAttribute('data-fill-region') || '';

    function closeList() {
      open = false;
      active = -1;
      list.hidden = true;
      list.innerHTML = '';
    }

    function renderList() {
      list.innerHTML = '';
      if (!filtered.length) {
        list.hidden = true;
        open = false;
        return;
      }
      filtered.forEach(function (it, idx) {
        var li = document.createElement('li');
        li.className = 'typeahead-option' + (idx === active ? ' is-active' : '');
        li.setAttribute('role', 'option');
        li.textContent = it.label || it.value;
        li.addEventListener('mousedown', function (e) {
          e.preventDefault();
          selectItem(it);
        });
        list.appendChild(li);
      });
      list.hidden = false;
      open = true;
    }

    function selectItem(it) {
      if (!it) return;
      hidden.value = it.value;
      // Keep the typed field as the country/language name only.
      // Dropdown may still show richer labels like "6 · Germany".
      input.value = it.value || it.label || '';
      closeList();
      if (fillLangSel) {
        var langRoot = document.querySelector(fillLangSel);
        if (langRoot) {
          var langHidden = null;
          var langInput = null;
          if (langRoot.matches('[data-typeahead]')) {
            langHidden = langRoot.querySelector('[data-typeahead-value]');
            langInput = langRoot.querySelector('[data-typeahead-input]');
          } else if (langRoot.tagName === 'INPUT') {
            langHidden = langRoot;
            var wrap = langRoot.closest('[data-typeahead]');
            if (wrap) langInput = wrap.querySelector('[data-typeahead-input]');
          }
          // Always sync language from the selected country (hidden or typeahead).
          if (langHidden && it.lang) {
            langHidden.value = it.lang;
            if (langInput) langInput.value = it.lang;
          }
        }
      }
      if (fillRegionSel && it.region) {
        var regionEl = document.querySelector(fillRegionSel);
        if (regionEl && 'value' in regionEl) regionEl.value = it.region;
      }
      root.dispatchEvent(new CustomEvent('typeahead:select', { detail: it, bubbles: true }));
    }

    function pickFirstOrExact() {
      var q = norm(input.value);
      if (!q) {
        hidden.value = '';
        return false;
      }
      for (var i = 0; i < items.length; i++) {
        if (norm(items[i].value) === q || norm(items[i].label) === q) {
          selectItem(items[i]);
          return true;
        }
      }
      filtered = filterItems(items, input.value);
      if (filtered.length) {
        selectItem(filtered[0]);
        return true;
      }
      if (required) {
        hidden.value = '';
        return false;
      }
      // optional: keep typed free text
      hidden.value = input.value.trim();
      closeList();
      return true;
    }

    input.addEventListener('input', function () {
      hidden.value = '';
      filtered = filterItems(items, input.value);
      active = filtered.length ? 0 : -1;
      renderList();
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowDown') {
        e.preventDefault();
        if (!open) {
          filtered = filterItems(items, input.value);
          active = 0;
          renderList();
          return;
        }
        active = Math.min(filtered.length - 1, active + 1);
        renderList();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active = Math.max(0, active - 1);
        renderList();
      } else if (e.key === 'Enter') {
        e.preventDefault();
        if (open && active >= 0 && filtered[active]) {
          selectItem(filtered[active]);
        } else {
          pickFirstOrExact();
        }
      } else if (e.key === 'Escape') {
        closeList();
      }
    });

    input.addEventListener('blur', function () {
      setTimeout(function () {
        closeList();
        if (required) {
          var q = norm(input.value);
          var match = null;
          for (var i = 0; i < items.length; i++) {
            if (norm(items[i].value) === q || norm(items[i].label) === q) {
              match = items[i];
              break;
            }
          }
          if (match) {
            selectItem(match);
          } else if (!hidden.value) {
            input.value = '';
            hidden.value = '';
          } else {
            input.value = hidden.value;
          }
        } else if (!hidden.value && input.value.trim()) {
          pickFirstOrExact();
        }
      }, 120);
    });

    input.addEventListener('focus', function () {
      filtered = filterItems(items, input.value);
      active = filtered.length ? 0 : -1;
      renderList();
    });

    var form = input.closest('form');
    if (form && required && !form.__typeaheadRequiredGuard) {
      form.__typeaheadRequiredGuard = true;
      form.addEventListener('submit', function (e) {
        var blocks = form.querySelectorAll('[data-typeahead][data-required="1"]');
        for (var i = 0; i < blocks.length; i++) {
          var h = blocks[i].querySelector('[data-typeahead-value]');
          var q = blocks[i].querySelector('[data-typeahead-input]');
          if (!h || !String(h.value || '').trim()) {
            e.preventDefault();
            if (q) {
              q.focus();
              if (q.setCustomValidity) {
                q.setCustomValidity('Select from the list (type, then press Enter).');
                q.reportValidity();
                q.setCustomValidity('');
              }
            }
            return;
          }
        }
      });
    }
  }

  function boot() {
    document.querySelectorAll('[data-typeahead]').forEach(initTypeahead);
    document.querySelectorAll('[data-domains-paste]').forEach(initDomainsPaste);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
