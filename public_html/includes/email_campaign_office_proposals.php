<?php
declare(strict_types=1);

/**
 * Office English proposal library for Campaign drafts.
 * Seeded into a team-visible project so Communication can search, copy, and paste.
 * Sign-off is “Best regards” only — each teammate adds their own name.
 */

function email_campaign_office_proposal_project_name(): string
{
    return 'Office proposals · English';
}

function email_campaign_office_proposal_sign_off(): string
{
    return 'Best regards';
}

/**
 * Latest published-article samples per country (two URLs when the sheet has them).
 * Picked from the office published-articles sheet by newest year/month/day.
 *
 * @return array<string, list<string>>
 */
function email_campaign_office_country_published_samples(): array
{
    return [
        'Austria' => [
            'https://www.kollermedia.at/2026/02/24/pendler-im-berufsverkehr-so-gewinnen-sie-jeden-morgen-wertvolle-zeit',
            'https://thegap.at/roadtrip-zum-musikfestival-im-ausland-perfekt-planen',
        ],
        'Belgium' => [
            'https://besteautobod.be/blog/uw-auto-optimaal-voorbereiden-op-de-technische-keuring',
            'https://goodbye.be/blog/hoe-blijf-je-actief-tijdens-het-reizen',
        ],
        'Bulgaria' => [
            'https://tvnovini.bg/oshte/lubopitno/reshavane-na-problemi-s-povredeni-ili-nedostapni-chasti-kakvo-da-pravim-kogato-onlayn-porachkata-se-obarka',
            'https://boliarinews.bg/2024/05/13/keep-clean-car',
        ],
        'Czech Republic' => [
            'https://www.aktualne.cz/zimni-motorovy-olej-v-cesku/r~efe96780955711f0bb77ac1f6b220ee8',
        ],
        'Denmark' => [
            'https://handyhand.dk/blog/derfor-ender-sa-mange-gor-det-selv-reparationer-med-en-returpakke',
            'https://festsangetaler.dk/fuldt-passagerantal-kraever-mere-af-bremser-end-de-fleste-tror',
        ],
        'Estonia' => [
            'https://buller.ee/sisuturundus/juhend-auto-pohihoolduse-kohta-uutele-juhtidele-pikaealisuse-ja-tookindluse-tagamine',
            'https://ralli.ee/voistlusvalmis-sidurid-mis-eristab-voidusoiduautode-sidurid-tavaparastest',
        ],
        'Finland' => [
            'https://ammattilehti.fi/blogi/2026/02/02/50166',
            'https://www.etlehti.fi/blogit/forbesnewsmag/tien-paalla-valmis-miten-valmistella-auto-pitkan-matkan-ajamiseen',
        ],
        'France' => [
            'https://android-mt.ouest-france.fr/application/la-piece-ne-convenait-pas-et-ca-na-pris-que-deux-minutes-pour-regler-le-probleme',
            'https://www.autosblog.fr/crochet-dattelage-sur-voiture-moderne-les-erreurs-qui-coutent-cher',
        ],
        'Germany' => [
            'https://velototal.de/2026/04/30/radurlaub-in-den-bergen-richtig-planen-und-sicher-ankommen',
            'https://www.das-marburger.de/2026/04/24/ist-mein-auto-noch-sicher-diese-warnsignale-sollten-sie-kennen',
        ],
        'Greece' => [
            'https://www.enimerotiko.gr/plus/aytokinito/pos-na-apotrepsete-koina-provlimata-kinitira-aytokinitoy',
            'https://arta-news.gr/2025/12/09/pos-na-epistrepsete-antallaktika-online-xoris-na-xasete-xrimata-sta-metaforika',
        ],
        'Hungary' => [
            'https://egriugyek.hu/mindenki-ugye/hogyan-vedd-meg-autod-telen-a-rozsdatol-es-gombatol',
            'https://antropos.hu/a-vegso-utra-valo-felkeszulesi-utmutato-az-auto-keszen-all-a-kalandra',
        ],
        'Italy' => [
            'https://www.savonanews.it/2026/08/20/leggi-notizia/argomenti/economia-1/articolo/perche-in-agosto-le-strade-del-savonese-mettono-a-dura-prova-la-tua-auto.html',
            'https://ciavula.it/2026/07/pastiglie-dei-freni-quali-tipi-scegliere-per-la-propria-auto',
        ],
        'Latvia' => [
            'https://www.1188.lv/zinas/skaidra-redzejuma-apgusana-ka-uzlabot-redzamibu-brauksanas-laika-lietus-laika/32143',
            'https://maminuklubs.lv/mazulis/gaisa-spilvenu-siksnas-un-spoguli-bernu-drosibas-nodrosinasana-transportlidzeklos301774',
        ],
        'Lithuania' => [
            'https://www.manokrastas.lt/esminis-reguliarios-technines-prieziuros-vaidmuo-siekiant-isvengti-didesnio-automobilio-remonto',
            'https://mamoszurnalas.lt/trikdziu-salinimo-vadovas-ka-daryti-kai-automobilis-neuzsiveda',
        ],
        'Netherlands' => [
            'https://www.cranendonck24.nl/artikel/koppeling-langer-laten-meegaan-door-je-rijstijl-aan-te-passen~c910rp',
            'https://www.valkenswaard24.nl/artikel/remklauw-defect-zorgt-voor-scheeftrekken-tijdens-het-remmen~3srv5b',
        ],
        'Norway' => [
            'https://rakt.no/bil/fem-feil-som-stopper-bilen-din-pa-norske-veier',
            'https://dailystory.no/kjor-gront-tips-for-a-redusere-bilens-miljopavirkning',
        ],
        'Poland' => [
            'https://www.infoilawa.pl/aktualnosci/item/82006-ile-wytrzyma-sprzeglo-i-jak-sprawic-zeby-sluzylo-jak-najdluzej',
            'https://extraswiecie.pl/materialy-partnerow/ktore-czesci-samochodowe-zuzywaja-sie-szybciej-podczas-jazdy-miejskiej-i-na-autostradzie-i-dlaczego-kierowcy-czesto-ignoruja-ten-czynnik',
        ],
        'Portugal' => [
            'https://www.ovarnews.pt/porque-os-carros-hibridos-estao-a-ganhar-popularidade-em-ovar-face-aos-eletricos',
            'https://informatico.pt/blog/ataque-ransomware-seguranca-veiculos-eletricos',
        ],
        'Romania' => [
            'https://www.3szek.ro/load/cikk/163945/hazi-gepkocsijavitas-%E2%80%93-egyszeru-utmutato-autok-javitasahoz-es-karbantartasahoz-beleertve-annak-megerteset-is-hogyan-csereljunk-ki-egy-legszurot-/x',
            'https://newsbv.ro/prelungirea-calatoriei-sfaturi-esentiale-de-intretinere-auto-diy-diyprelungirea-calatoriei-sfaturi-esentiale-de-intretinere-auto-diy-diy',
        ],
        'Slovakia' => [
            'https://www.news.sk/clanky/auto-moto/5029-filtre-do-auta-a-preco-ich-treba-menit',
            'https://www.infoglobe.sk/uloha-stieracov-celneho-skla-v-automobiloch-pracovny-modul-a-sposob-vymeny',
        ],
        'Spain' => [
            'https://senderosgr.es/blog/2026/03/14/lo-que-debes-revisar-en-el-coche-antes-de-adentrarte-en-sierra-nevada',
            'https://www.navarrainformacion.es/2026/01/15/arrancar-tu-diesel-en-invierno-sin-fallos-en-el-pirineo',
        ],
        'Sweden' => [
            'https://accademos.se/blogg/begagnad-bil-utan-dyra-overraskningar-sa-kontrollerar-du-innan-kop',
            'https://skvallra.se/teknik-prylar/vad-ska-man-kontrollera-fore-en-lang-bilresa-viktiga-tips-for-bilunderhall',
        ],
        'Switzerland' => [
            'https://www.wetter.ch/Gewitter+Starkregen+Sturmboeen+So+bleiben+Autofahrer+auf+der+Strasse+sicher/704888/detail.htm',
            'https://www.letemps.ch/contenus-partenaires/comment-autodoc-resout-le-defi-europeen-a-longue-traine-des-pieces-automobiles',
        ],
        'United Kingdom' => [
            'https://www.thetraveldaily.co.uk/article/2026/04/25/how-plan-perfect-european-road-trip-without-stress',
            'https://www.varsity.co.uk/sponsored/the-autodoc-customer-experience-explained-shipping-returns-and-support',
        ],
    ];
}

/**
 * @param list<string> $urls
 * @return list<string>
 */
function email_campaign_office_country_sample_bodies(string $country, array $urls): array
{
    $urls = array_values(array_filter($urls, static fn ($u) => is_string($u) && trim($u) !== ''));
    $block = implode("\n\n", $urls);
    $two = count($urls) >= 2;
    $introA = $two
        ? "Of course — here are two recent articles we published in {$country}:"
        : "Of course — here is a recent article we published in {$country}:";
    $introB = $two
        ? "You asked for a sample of our published work. These two {$country} articles are recent examples:"
        : "You asked for a sample of our published work. This {$country} article is a recent example:";
    $introC = $two
        ? "Here are two live publications from {$country} so you can see the standard we aim for:"
        : "Here is a live publication from {$country} so you can see the standard we aim for:";

    return [
        "Hello,
thank you so much for your reply. I really appreciate your time.

{$introA}

{$block}

These are only samples of work already live. The article for your website would be written from scratch by a native-speaking author, for your readers, so that it adds real value to your pages.

Our advertising budget is very limited :( so I would be very grateful if you could offer us the most competitive rate you can. We can pay by PayPal or bank transfer immediately after publication, or within 24 hours.

If you could also share your guidelines and whether dofollow links are possible, I would be delighted to proceed.

Awaiting your kind reply.",
        "Good morning,
thank you for writing back. That is very kind of you.

{$introB}

{$block}

For your site we would prepare a new, original piece that fits your audience — useful content, not a promotional page.

May I also ask, with respect, for the best discounted price you could offer? Our advertising budget is genuinely very small :( Payment is PayPal or bank transfer, as soon as the article is published, or within 24 hours.

Your guidelines would of course be followed.",
        "Hello,
thank you. I am grateful you asked for an example.

{$introC}

{$block}

The text for your website would be written from scratch and meant to help your readers.

Because our budget for this campaign is very, very limited :( I would like to kindly request your most flexible fee. If the price can be a little easier for us, we will confirm quickly and pay immediately after publication, or within 24 hours, by PayPal or bank transfer.

Could you also tell me your requirements and whether the links can be dofollow?",
    ];
}

/**
 * @return list<array{title:string,category:string,sad:bool,bodies:list<string>}>
 */
function email_campaign_office_country_sample_groups(): array
{
    $groups = [];
    foreach (email_campaign_office_country_published_samples() as $country => $urls) {
        $groups[] = [
            'title' => 'Sample · ' . $country,
            'category' => 'reply',
            'sad' => true,
            'bodies' => email_campaign_office_country_sample_bodies($country, $urls),
        ];
    }
    return $groups;
}

/**
 * @return list<array{title:string,category:string,sad:bool,sort:int,body:string}>
 */
function email_campaign_office_proposal_catalog(): array
{
    static $items = null;
    if ($items !== null) {
        return $items;
    }
    $sign = email_campaign_office_proposal_sign_off();
    $groups = [
        [
            'title' => 'First outreach · requesting',
            'category' => 'first_outreach',
            'sad' => true,
            'bodies' => [
                'Hello,

I hope you are doing well.

I came across your website while searching online and I was genuinely impressed by your content. I am writing to kindly ask whether you might consider a small collaboration with us.

We would be very grateful for the opportunity to publish high-quality, original articles on your website, written for your readers and intended to add real value to your pages.

Our advertising budget for this campaign is unfortunately very limited :( so I would like to kindly ask if you could share your most flexible publication rate. We always try to work with the best possible discounted price, while still delivering careful, useful content for your audience.

To give you an idea of the quality we provide, here is one example of a published article:

https://www.savonanews.it/2026/08/20/leggi-notizia/argomenti/economia-1/articolo/perche-in-agosto-le-strade-del-savonese-mettono-a-dura-prova-la-tua-auto.html

If you accept sponsored or guest posts, could I please kindly ask you for:
1. Your best publication fee, and whether a small discount might be possible
2. Any content guidelines or requirements
3. Whether you can provide dofollow links

As soon as the article is published, we can pay immediately by PayPal or bank transfer, or within 24 hours at the latest.

I would be truly grateful if we could find a rate that works for both of us.

Looking forward to your kind reply.',
                'Good morning,

I hope this message finds you well.

Your website caught my attention because of the quality of your articles, and I wanted to ask, very respectfully, if a collaboration might be possible.

We are looking for a publisher who would allow us to place original articles that are useful to your readers, not generic promotional text.

I should mention that our advertising budget is very, very limited :( For that reason, I would be grateful if you could consider a discounted rate, or your most flexible price. We are happy to work around your guidelines, and we take care to match the tone of the site.

Here is an example of work we have already published:

https://www.savonanews.it/2026/08/20/leggi-notizia/argomenti/economia-1/articolo/perche-in-agosto-le-strade-del-savonese-mettono-a-dura-prova-la-tua-auto.html

Would you be so kind as to let me know:
- the best fee you could offer us
- your content rules
- whether dofollow links are available

We can settle the payment by PayPal or bank transfer right after the article goes live, or within 24 hours.

Thank you for your time. I hope we can find a fair way to work together.',
                'Hello,

I hope you are well.

I am reaching out because I admire the content on your website and I would like to ask, politely, whether you ever accept sponsored or guest articles.

What we can offer is original writing, prepared for your audience, with the aim of adding value to your pages rather than simply placing a link.

Unfortunately, the budget we have for advertising is extremely tight :( I therefore have to ask if you might be able to offer a more accessible, discounted publication price. Even a small gesture on the rate would help us a great deal.

You can see an example of our published content here:

https://www.savonanews.it/2026/08/20/leggi-notizia/argomenti/economia-1/articolo/perche-in-agosto-le-strade-del-savonese-mettono-a-dura-prova-la-tua-auto.html

If you are open to this, I would be grateful for:
1) your most competitive fee
2) any requirements we must follow
3) confirmation of dofollow / nofollow

Payment is not a problem once the article is online: PayPal or bank transfer, immediately or within 24 hours.

I would be thankful for any flexibility you can offer.',
            ],
        ],
        [
            'title' => 'Sample · they asked for an example',
            'category' => 'reply',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you so much for your reply. I really appreciate your time.

Of course. Here is an example of our published work:

https://www.savonanews.it/2026/08/20/leggi-notizia/argomenti/economia-1/articolo/perche-in-agosto-le-strade-del-savonese-mettono-a-dura-prova-la-tua-auto.html

The articles we would prepare for your website would be original, written by native-speaking authors, and tailored to your readers, with the aim of adding genuine value to your content.

Our advertising budget is very limited :( so I would be very grateful if you could offer us the most competitive rate you can. In return, we move quickly: payment by PayPal or bank transfer immediately after publication, or within 24 hours.

If you could kindly also share your guidelines and whether dofollow links are possible, I would be delighted to proceed.

Awaiting your kind reply.',
                'Good morning,
thank you for writing back. That is very kind of you.

I am happy to share a sample so you can judge the quality yourself:

https://www.savonanews.it/2026/08/20/leggi-notizia/argomenti/economia-1/articolo/perche-in-agosto-le-strade-del-savonese-mettono-a-dura-prova-la-tua-auto.html

For your site, we would write new, original pieces, in a style that fits your audience, so the pages remain useful and not promotional.

May I also ask, with respect, for the best discounted price you could offer? Our advertising budget is genuinely very small :( We can pay as soon as the article is published — PayPal or bank transfer, at once or within 24 hours.

Your guidelines would of course be followed.',
                'Hello,
thank you. I am grateful you asked.

Please find one published example here:

https://www.savonanews.it/2026/08/20/leggi-notizia/argomenti/economia-1/articolo/perche-in-agosto-le-strade-del-savonese-mettono-a-dura-prova-la-tua-auto.html

This is only to show the standard we aim for. The text for your website would be written from scratch and meant to help your readers.

Because our budget for this campaign is very, very limited :( I would like to kindly request your most flexible fee. If the price can be a little easier for us, we will confirm quickly and pay immediately after publication, or within 24 hours, by PayPal or bank transfer.

Could you also tell me your requirements and whether the links can be dofollow?',
            ],
        ],
        [
            'title' => 'Quality · original not ChatGPT',
            'category' => 'reply',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you for raising this. I completely understand.

Our articles are written by native-speaking authors and reviewed by our editorial team. They are original, well-structured, and written for your audience. We do not send raw automated text. Our goal is to add value to your website and respect the quality and tone of your pages.

Because our advertising budget is very limited :( I would still kindly ask whether a more flexible rate might be possible. We can pay by PayPal or bank transfer immediately after publication, or within 24 hours.

I would be very grateful for your understanding.',
                'Good morning,
thank you for the question. It is a fair one.

We work with native-speaking writers and an editorial check before anything is sent to you. The content is original and prepared for your readers. We are careful not to send unedited machine text.

We hope this also shows that we take your website seriously. If, with that in mind, you could consider a more accessible discounted rate, I would be truly thankful :( Our advertising budget is extremely limited. Payment can be made right after publication, or within 24 hours, via PayPal or bank transfer.',
                'Hello,
I appreciate you mentioning this.

You have my word that the articles will be original, written for your audience, and reviewed before we send them. We want the content to feel at home on your site and to be useful, not copied and not promotional.

If you are comfortable with that, may I politely ask again for the best price you can offer? We are working with a very small advertising budget :( In return, we pay quickly: PayPal or bank transfer as soon as the article is live, or within 24 hours.

Thank you for your time.',
            ],
        ],
        [
            'title' => 'Topics · they asked for niche / category',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you for your question. I am happy to explain.

The articles will be written to match the content of your website and aligned with your niche. We look at the subjects you already cover, and we prepare pieces that sit naturally in that same category for your readers.

I believe this will add real value to your website and to your audience, and I hope it will be useful for both sides.

The content will be 100% unique, written by native-speaking authors, checked by our editorial team, and not generated by AI.

If you have a preferred section or category on the site, please tell me and we will follow it. I can also send the three topic titles for your approval before we write.

Looking forward to your reply.',
                'Good morning,
thank you for asking about the topics / niche / category.

We do not send generic articles. Each piece is prepared for your website: related to what you already publish, in the same niche, and written so it can sit in the matching category on your site.

The aim is content that helps your readers and strengthens your pages, not a promotional text.

Everything we send is original, high quality, and written by people — unique, AI-free, and reviewed before it reaches you.

If you would like, I can share a short list of proposed topics next, and you can say if any title should be changed.',
                'Hello,
thank you. That is a fair question.

In short: the articles will follow your website’s niche. We choose subjects that fit your existing content, so the category on your site stays consistent and the pages remain useful for your readers.

I hope this collaboration is beneficial for both your website and your audience.

We can provide 100% unique, quality content, written from scratch and free from AI-generated text.

Please let me know if you have a category you prefer (for example news, guides, or a specific section). If not, we will propose topics that match what we see on {domain}.',
            ],
        ],
        [
            'title' => '3 articles · ask group rate',
            'category' => 'offer',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you for your reply.

I would like to publish three articles on your websites. As this is a group order, I was wondering if you could offer me your best rate for these three publications.

I am seeking the most competitive discount you can offer me for this collaboration :( If we can agree on a reasonable flat rate, I would be delighted to proceed promptly.

Our advertising budget is very limited :( In return, we will provide original, high-quality articles, written by native-speaking authors and specifically tailored to your readers, so that the content adds real value to your website.

As soon as the articles are live, we can pay immediately by PayPal or bank transfer, or within 24 hours after publication.

Awaiting your reply.',
                'Hello,
thank you very much for your reply.

I would like to publish three articles on your websites. Since this involves a bulk order, I wanted to ask if you could offer me your best possible price for all three publications.

I am looking for the most competitive discount you can offer for this collaboration :( Our advertising budget is very limited. If we can agree on a reasonable package price, I would like to proceed quickly.

We can pay by PayPal or bank transfer immediately after publication, or within 24 hours.

I look forward to your reply.',
                'Hello,
thank you for writing back. I appreciate it.

Would it be possible to place three articles with you? Because it is a small group order, I would be grateful if you could share your most flexible rate for the three publications.

Our advertising budget is very limited :( so I kindly ask for the best discount you can offer. If we can agree on a reasonable flat price, I would be happy to proceed without delay.

Looking forward to your reply.',
            ],
        ],
        [
            'title' => 'Rate card · one site only',
            'category' => 'offer',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you very much for your reply, and for the clear overview of your websites.

I would like to publish three articles on {domain}. Since this is a bulk order, I wanted to ask if you could offer me your best possible price for all three publications on this website.

I am looking for the most competitive discount you can offer for this collaboration. The percentages on the rate card are helpful, but our advertising budget is very, very limited :( so I would be grateful if a more flexible package price could be considered.

If we can agree, we will provide original articles that add value to the site, and we can pay by PayPal or bank transfer immediately after publication, or within 24 hours.

I look forward to your reply.',
                'Hello,
thank you for the media kit. That is very useful.

At the moment I would like to focus on {domain} only, with three articles. May I kindly ask for your best discounted rate for these three publications on this site?

Our campaign budget is extremely limited :( so the standard package discount is still difficult for us. If you could offer a more accessible flat price, I would be delighted to proceed quickly.

We write the content ourselves with native-speaking authors, so I hope the extra writing fee would not be needed.

Payment can be PayPal or bank transfer, right after the articles are live, or within 24 hours.',
                'Hello,
thank you, and I hope you are having a good day.

I have looked at your list with interest. For now, I would like to start with three articles on {domain}. If this collaboration goes well, I may consider other websites in your group later.

Could you please offer me the most competitive price for these three items? Our advertising budget is very limited :( and I have to ask for a stronger discount than the usual 10% if that is possible.

We will take care of original, well-structured content for your readers, and we pay promptly after publication.

Thank you for considering this.',
            ],
        ],
        [
            'title' => '10% off · still too high',
            'category' => 'offer',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you for the 10% discount. I truly appreciate your willingness to work with us.

Unfortunately, even with this reduction, the amount is still far above our advertising budget :( I would like to kindly ask whether a more competitive package price for the three articles on {domain} might be possible.

We would provide original articles, written by native speakers and tailored to your audience, and we can pay by PayPal or bank transfer immediately after publication, or within 24 hours.

I hope you can help us a little more on the rate :(',
                'Hello,
thank you. The 10% is kind, and I do not take it for granted.

I still have to ask for further flexibility :( Our budget for this campaign is very, very limited, and I would be grateful if you could look at a lower flat rate for the three publications on this website.

If we can find a number we can actually pay, we will proceed quickly and keep the quality high.

Looking forward to your reply.',
                'Hello,
thank you for coming down a little.

Would it be possible to go further? I am not in a position to match this package price with our current advertising budget :( A more accessible rate for the three articles would mean a great deal to us.

In return: original content, aligned with your site, and fast payment after publication (PayPal or bank transfer, at once or within 24 hours).

I hope you can consider it :(',
            ],
        ],
        [
            'title' => '3 articles · counter €100',
            'category' => 'offer',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you for your reply.

I can proceed with the three articles and would like to offer (100 × 3 = €300), which amounts to €100 per article.

All articles are written by native speakers and specifically tailored to your website\'s audience. The content is original, well-structured, and designed to offer your readers real added value, while aligning with the quality and tone of your website.

Our advertising budget is very, very limited :( I hope you will consider my offer, and I would be happy to enter into this collaboration.

I look forward to your response.',
                'Hello,
thank you.

With our very limited advertising budget :( the most I can propose at this stage is €100 per article, so €300 for the three publications on {domain}.

I know this is a discounted request, and I ask it with respect. If you could accept it, we would start at once. The articles would be original, written by native-speaking authors, and meant to add value to your pages.

We can pay by PayPal or bank transfer immediately after publication, or within 24 hours.

I would be very grateful if you could consider this :(',
                'Hello,
thank you for your time.

May I kindly put this figure forward: €300 in total for the three articles (€100 each)? This is what our advertising budget can manage if you are willing to help us :(

We would still take care of the quality — native speakers, original writing, and content that fits your readers.

If this is acceptable, I would like to proceed without delay.',
            ],
        ],
        [
            'title' => '3 articles · counter €120',
            'category' => 'offer',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you for your reply.

I fully understand that €100 is too low for the quality you offer, and I respect the work behind your websites.

I can proceed with the three articles and would like to offer (120 × 3 = €360), which amounts to €120 per article.

All articles are written by native speakers and specifically tailored to your website\'s audience. The content is original, well-structured, and designed to offer your readers real added value, while aligning with the quality and tone of your website.

Unfortunately our advertising budget is still very limited :( I hope you will consider my offer, and I would like to proceed with this collaboration.

I look forward to your response.',
                'Hello,
thank you. I understand it costs a lot to keep the websites running, and I do not want to undervalue that.

From our side, the advertising budget is still very limited :( Could you please consider €120 per article (€360 for the three)? This is already a stretch for us, and I ask it as a genuine request.

We will deliver original, careful content for your readers, and we can pay quickly after publication — PayPal or bank transfer, immediately or within 24 hours.

I hope we can meet a little closer :(',
                'Hello,
thank you for explaining.

I cannot reach €250 per article with this campaign, even though I respect your position :( Would €120 each (€360 in total) be something you could accept for three articles on {domain}?

If you can help us with this discounted price, we will move forward at once and keep the quality high.

Looking forward to your reply.',
            ],
        ],
        [
            'title' => 'Only this site for now',
            'category' => 'offer',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you for the offer on the other websites.

At the moment, I am only interested in placing the three articles on {domain}. In the future, I might want to buy something on the other sites if we can finalize this deal.

I would be grateful if we could first agree on a fair discounted rate for these three items on this website. Our advertising budget is very limited right now :(

We can pay by PayPal or bank transfer immediately after publication, or within 24 hours.',
                'Hello,
thank you. That is kind of you.

For now I would like to keep the order to {domain} only. If this collaboration works well, I would be happy to look at your other websites later.

Could we please focus on the best possible price for three articles on this site first? That would help us a great deal with our current budget :(

Payment would still be fast after publication.',
                'Hello,
thank you for thinking of a larger package.

I am afraid I cannot order nine articles at this stage :( I would like to start with three on {domain}. If we can close this at a rate we can afford, it will be much easier for me to come back for the other magazines later.

I hope you can still help us with a more accessible price on this first order.',
            ],
        ],
        [
            'title' => '3 articles · maximum €150',
            'category' => 'offer',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you very much for your response and for your willingness to work with us.

After careful consultation with my team, we have reviewed the budget once again. For {domain}, our final offer is €450 for three items (€150 per item). This is the maximum budget we have been able to allocate for this website, and unfortunately we cannot exceed it :(

I fully understand and respect your pricing, and I sincerely hope that you will consider our offer :( We would greatly appreciate starting our collaboration with these three items, and I hope this can be the beginning of a long-term partnership.

We will provide original articles that add value to your website, and we can pay by PayPal or bank transfer immediately after publication, or within 24 hours.

Thank you for your time and attention. I look forward to your response.',
                'Hello,
thank you. I am grateful you are still discussing this with me.

I spoke with my team again. The most we can do for three articles on {domain} is €150 each, so €450 in total. I am sorry, but we cannot go above this with our current advertising budget :(

I hope you can accept this as a first collaboration :( If it goes well, it will be easier for us to return for other websites in your group later.

Payment would be fast: PayPal or bank transfer, right after publication or within 24 hours.

I would be very thankful if you could say yes.',
                'Hello,
thank you for meeting us part of the way.

I have to be honest: €200 per article is still above what we can allocate :( After reviewing the budget, our maximum for {domain} is €450 for the three publications (€150 each). We cannot exceed this.

I ask this with respect for your work. If you can accept it this time, we will take care of the content, and I hope we can grow the partnership afterwards.

I look forward to your reply.',
            ],
        ],
        [
            'title' => 'Last polite ask',
            'category' => 'offer',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you again for your patience.

I do not want to take too much of your time. I am writing only to kindly ask if there is any last possibility of a better discounted rate for the three articles.

Our advertising budget is truly very limited :( and even a small reduction would help us a lot. We would still provide original, high-quality content that adds value to your website, and we would arrange payment by PayPal or bank transfer immediately after the articles are published, or within 24 hours.

If this is not possible, I will of course respect your decision.

Thank you so much for considering this one more time.',
                'Good morning,
I will keep this short.

This is my last request on the price, and I make it with respect. If you are able to offer any further discount on the three articles, I would be very grateful :( Our advertising budget is extremely limited.

We remain ready with original content for your readers, and with fast payment after publication (PayPal or bank transfer, at once or within 24 hours).

If the answer is no, I understand completely.',
                'Hello,
thank you for still reading my messages.

Before I close this point, may I ask once, very politely, whether a more accessible price is possible? I would not ask if our advertising budget were not so tight :(

If you can help, we will proceed immediately, keep the quality high, and pay right after publication or within 24 hours.

If you cannot, I will respect that and thank you for your time.',
            ],
        ],
        [
            'title' => 'They said no more discount',
            'category' => 'offer',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you for your honesty. I respect your rates.

I will check whether our limited advertising budget can cover the amount you indicated :( If we can go ahead, I will confirm as soon as possible.

If a small reduction for three articles becomes possible on your side, I would be very grateful, because every bit of flexibility helps us. We remain ready to deliver quality content and to pay immediately after publication, or within 24 hours, by PayPal or bank transfer.

Thank you again for your time.',
                'Good morning,
thank you. I understand your position.

Let me see what I can do internally. If the budget allows, I will write back to confirm :(

Should you find any room for a gesture on the price in the meantime, I would of course be thankful. The content and the fast payment after publication would still stand.',
                'Hello,
I appreciate your clear answer.

I will review our numbers and come back to you. Our advertising budget is very limited :( so I cannot promise yet, but I will try.

Thank you for considering us. If anything changes on the fee, even slightly, please tell me. We can still pay quickly after the articles are live.',
            ],
        ],
        [
            'title' => 'We accept the price',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,

Thank you very much. I am truly grateful, and I am delighted to accept your offer of €[amount] for the three articles. I sincerely appreciate your time, your cooperation, and your willingness to work with us, especially given our limited budget.

Our writers and editorial team will carefully prepare original articles, tailored to your audience, so that they add value to your website and match your quality and tone.

In the meantime, could I please kindly ask you to confirm a few details so we can follow your rules from the start?
How many links can you include in each article?
Will these links be dofollow, or will they be nofollow or sponsored links?
How many images can be included in each article?
Are there any other content or formatting requirements we should comply with?

As soon as the articles are published, we can pay immediately by PayPal or bank transfer, or within 24 hours. Whichever you prefer is fine for us.

Thank you again. I look forward to working with you.',
                'Good morning,
thank you. That is very kind of you.

I am happy to accept €[amount] for the three articles. Thank you for making this possible for us.

We will start the writing with care, so the pieces fit your readers and add something useful to your site.

Before we send the drafts, could you please confirm:
- number of links per article
- dofollow, nofollow, or sponsored
- images allowed
- any other rules we should follow

We will pay by PayPal or bank transfer as soon as the articles are online, or within 24 hours.',
                'Hello,
I am very grateful. We accept your offer of €[amount] for the three publications.

Thank you for working with our limited budget. We will make sure the articles are original and appropriate for your website.

May I ask you for the publication details, so we do not make mistakes?
1. How many links per article?
2. Dofollow, nofollow, or sponsored?
3. How many images?
4. Any other requirements?

Payment will follow publication immediately, or within 24 hours, via PayPal or bank transfer.',
            ],
        ],
        [
            'title' => 'Confirm guidelines · writing started',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you so much for providing all the details and publication conditions. Everything is clear, and I really appreciate your clarity.

Payment by PayPal or bank transfer suits us perfectly. We will arrange it immediately after publication, or within 24 hours.

Our team has already begun working on the three articles. We are selecting topics that are relevant to your website and useful to your readers, with informative and genuinely helpful content.

We will follow your guidelines on links, images, word count, and tone, and we will make sure the articles add value to your pages.

As soon as they are ready, I will send them to you for publication.

Thank you again for your time and your patience.',
                'Good morning,
thank you. Your conditions are understood, and we will follow them.

PayPal or bank transfer after publication is ideal for us. We will pay at once, or within 24 hours.

The three articles are already in progress. We are choosing topics that fit your site and should be useful to your readers.

I will send the texts as soon as they are ready for you to publish.

Thank you again for this collaboration.',
                'Hello,
thank you for the clear instructions. We will respect them fully.

Our team is already writing the three articles, with your audience in mind, so the content adds value rather than reading like advertising.

When they are ready, I will send them to you. After they are live, we will settle payment immediately or within 24 hours, by PayPal or bank transfer.

I am grateful for your help.',
            ],
        ],
        [
            'title' => 'Here are the topics',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you for your message. I appreciate it.

May I kindly share the three topics we would like to cover? Each article will be original, informative, and written in a neutral tone for your readers, with no self-promotion, so that it adds value to your website:

1. [Topic 1]
2. [Topic 2]
3. [Topic 3]

If any of these do not fit your editorial line, I would be grateful if you could tell me. We will propose an alternative immediately.

We will follow your conditions as discussed. After publication, we can pay at once by PayPal or bank transfer, or within 24 hours.

Thank you so much.',
                'Good morning,
thank you.

Here are the three topics, if you are happy with them:

1. [Topic 1]
2. [Topic 2]
3. [Topic 3]

Please say if one should be changed. We want the articles to feel right for your site.

After publication, payment can be made immediately or within 24 hours.',
                'Hello,
I hope you are well.

Could I ask you to look at these three topic ideas?

• [Topic 1]
• [Topic 2]
• [Topic 3]

If something is not suitable, we will replace it without delay. The writing will stay original and useful for your readers.

We remain ready to pay right after the articles go live, or within 24 hours, by PayPal or bank transfer.',
            ],
        ],
        [
            'title' => 'Here are the links',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you. Could I please share the links we would like to include? We will of course stay within your limit per article:

Article 1:
- [URL 1]
- [URL 2]

Article 2:
- [URL 1]
- [URL 2]

Article 3:
- [URL 1]
- [URL 2]

If any URL is not suitable for your website, I would be very grateful if you could let me know. We will replace it without delay.

The articles will remain informative, with a neutral tone and no self-promotion, so they are useful for your readers.

After publication, payment can be made immediately by PayPal or bank transfer, or within 24 hours.',
                'Good morning,
thank you.

Please find the destination links below. We will not exceed the number of links you allow.

Article 1: [URL 1] / [URL 2]
Article 2: [URL 1] / [URL 2]
Article 3: [URL 1] / [URL 2]

If one of these does not fit your policy, just tell me and we will change it.

Payment after publication will be prompt: PayPal or bank transfer, at once or within 24 hours.',
                'Hello,
may I send the links for your review?

1) [URL] and [URL]
2) [URL] and [URL]
3) [URL] and [URL]

We will keep the articles useful and not promotional. If a link is a problem, we will replace it immediately.

Once the pieces are live, we can pay within 24 hours at the latest.',
            ],
        ],
        [
            'title' => 'Topics and links together',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you. Please find below the three topics and the links. If anything is not suitable, I kindly ask you to tell me and we will adjust it at once.

Article 1 — [Topic]
- [URL]
- [URL]

Article 2 — [Topic]
- [URL]
- [URL]

Article 3 — [Topic]
- [URL]
- [URL]

We will respect your guidelines and prepare content that adds value to your website.

After the articles are live, we can pay immediately by PayPal or bank transfer, or within 24 hours.

Thank you so much for your help.',
                'Good morning,
thank you.

Here is the full picture, for your approval:

1. [Topic] — [URL], [URL]
2. [Topic] — [URL], [URL]
3. [Topic] — [URL], [URL]

Please tell me if you would like a topic or a link changed. We want this to work well for your site.

We will pay as soon as publication is done, or within 24 hours.',
                'Hello,
I hope this is useful.

Could you please confirm these three pieces?

• [Topic] with [URL] and [URL]
• [Topic] with [URL] and [URL]
• [Topic] with [URL] and [URL]

If something does not fit, we will correct it immediately. The articles will stay original and written for your readers.

PayPal or bank transfer can follow publication at once, or within 24 hours.',
            ],
        ],
        [
            'title' => 'Invoice · our billing details',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you. Payment after publication is perfect for us, and we can do it immediately or within 24 hours, by PayPal or bank transfer, whichever you prefer.

May I kindly send the details for the invoice?

Company name: [company]
Address: [address]
VAT / tax number: [VAT]
Email for the invoice: [email]

As soon as the three articles are live and we receive the invoice, we will arrange payment without delay.

Thank you again.',
                'Good morning,
thank you.

Please use the following details for the invoice:

[company]
[address]
VAT: [VAT]
Email: [email]

We will pay by PayPal or bank transfer as soon as the articles are published, or within 24 hours of the invoice.',
                'Hello,
of course. Here is our billing information:

Name: [company]
Address: [address]
Tax / VAT: [VAT]
Invoice email: [email]

PayPal or bank transfer is fine. We will settle it immediately after publication, or within 24 hours.',
            ],
        ],
        [
            'title' => 'Please send PayPal or bank details',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you.

Could I please kindly ask you to send your preferred payment details — PayPal, or bank details (account holder, IBAN, BIC/SWIFT, and payment reference)?

We will pay immediately after publication, or within 24 hours of receiving the invoice / live URLs.

Thank you so much.',
                'Good morning,
thank you.

When you have a moment, could you send your PayPal address or your bank details for the transfer?

We will make the payment as soon as the articles are live, or within 24 hours.',
                'Hello,
may I ask for your payment information?

PayPal is fine, and bank transfer is also fine. Please send whichever you prefer, including IBAN and BIC if it is a transfer.

We do not delay: payment after publication, immediately or within 24 hours.',
            ],
        ],
        [
            'title' => 'Articles ready · please publish',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,

The three articles are ready. Please find them below / in the attached files.

We have tried to follow your guidelines carefully and to prepare content that is useful for your readers and adds value to your website.

When you have a moment, could I kindly ask you to publish them and send me the live URLs? As soon as they are online, we will arrange payment immediately, or within 24 hours, by PayPal or bank transfer.

Thank you so much for your cooperation.',
                'Good morning,
I hope you are well.

Please find the three articles attached / below. We have written them with your audience in mind.

If they are acceptable, I would be grateful if you could publish them when you can and send me the URLs. We will then pay at once, or within 24 hours.',
                'Hello,
the drafts are ready for you.

I have attached the three articles / pasted them below. Please tell me if anything should be adjusted before they go live.

Once they are published, we can send the payment immediately or within 24 hours, by PayPal or bank transfer.

Thank you for your help.',
            ],
        ],
        [
            'title' => 'Please send the live URLs',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you very much for publishing the articles. I really appreciate it.

When you have a moment, could you please send me the live URLs so we can check everything is correct?

As soon as we have them (and the invoice, if you issue one), we will make the payment immediately, or within 24 hours, by PayPal or bank transfer.

Thank you again.',
                'Good morning,
thank you for putting the articles online.

Could you kindly share the three live links? We would like to check them, and then we will pay without delay — immediately or within 24 hours.',
                'Hello,
thank you.

May I ask for the published URLs? Once I have them, we will arrange PayPal or bank transfer at once, or within 24 hours.

I am grateful for your work on this.',
            ],
        ],
        [
            'title' => 'Please send the invoice',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,
the articles look good. Thank you so much.

Could I kindly ask you to send the invoice so we can arrange payment at once? We will pay by PayPal or bank transfer immediately, or within 24 hours.',
                'Good morning,
thank you. Everything looks fine on our side.

Please send the invoice when you can. We will settle it immediately, or within 24 hours.',
                'Hello,
thank you for the publications.

Whenever the invoice is ready, please send it through. Payment will follow at once, or within 24 hours, by PayPal or bank transfer.',
            ],
        ],
        [
            'title' => 'Payment has been sent',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,

Thank you. The payment has been sent (PayPal / bank transfer).

If you need the reference or a receipt, I would be happy to send it.

It is a real pleasure to work with you. If a further collaboration is possible later, I would be grateful to stay in touch.

Thank you again.',
                'Good morning,
the transfer / PayPal payment has been made.

Please let me know if it has not arrived within the usual time, and I will check it at once.

Thank you for this collaboration. I hope we can work together again.',
                'Hello,
thank you. Payment is on its way / has been completed.

I am grateful for your help. If you are open to more articles later, I would be glad to ask again.

Thank you.',
            ],
        ],
        [
            'title' => 'Nofollow · is dofollow possible',
            'category' => 'offer',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you for clarifying. I appreciate your honesty.

May I kindly ask whether a dofollow link without mention might be possible, even at a different rate? Our budget is very limited :( so I would also be grateful for the most flexible package price you can offer for three articles.

We would still deliver original, useful content for your readers, and we would pay immediately after publication, or within 24 hours, by PayPal or bank transfer.

If dofollow is truly not possible, please tell me the best conditions you can offer and I will try to make it work.

Thank you so much.',
                'Good morning,
thank you for the explanation.

Would a dofollow option exist, perhaps at another price? I ask because it would help us a lot, and our advertising budget is already very tight :(

If not, I would still be grateful for the best discounted rate you can offer for three articles with the conditions you do allow. We can pay quickly after publication.',
                'Hello,
thank you.

I understand if dofollow is not standard. If there is any way to include it, even with a different fee, I would be thankful if you could tell me.

If the answer is no, please share the most accessible price for three articles under your rules :( We will keep the content useful, and payment would follow publication within 24 hours at the latest.',
            ],
        ],
        [
            'title' => 'Prefer pay after publication',
            'category' => 'reply',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you for your message.

If it is possible, we would be very grateful to pay immediately after publication, or within 24 hours, by PayPal or bank transfer. This helps us a great deal with our limited advertising budget :( and we still move very quickly once the articles are live.

If payment before publication is strictly required, please tell me and I will see what we can do.

Thank you for understanding.',
                'Good morning,
thank you.

Would after-publication payment be acceptable? We can send it at once, or within 24 hours, by PayPal or bank transfer. That is much easier for our budget :( and there is no long delay on our side.

If you cannot work that way, please let me know and I will check internally.',
                'Hello,
I hope you are well.

May I kindly ask if we could pay once the articles are online? We do this immediately, or within 24 hours. It is not because we wish to delay you; it is because our advertising budget is very limited :(

If prepayment is the only option, I will try to find a solution.',
            ],
        ],
        [
            'title' => 'Contact later',
            'category' => 'soft_no',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you for letting me know. I completely understand.

I would be very grateful if you could keep us in mind. When you are ready, I would still kindly ask for the most flexible rate you can offer for three articles, as our advertising budget is very limited :( We would provide quality content that adds value to your website, and we would pay immediately after publication, or within 24 hours.

Thank you again for your time.',
                'Good morning,
thank you. I will not press you now.

When the moment is better, I would be glad to write again. I would still hope for a discounted package price, because our budget is small :( and we remain ready with original articles and fast payment after publication.',
                'Hello,
I appreciate your reply, and I respect the timing.

Please feel free to contact me when you are available. We would still love to work with you, asking only for the most accessible rate you can offer :( with quality content and payment within 24 hours after the articles are live.

Thank you.',
            ],
        ],
        [
            'title' => 'They want changes',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you for the feedback. I really appreciate it.

Of course. We will revise the article according to your comments and send the updated version as soon as it is ready. We want the content to match the quality and tone of your website and to be useful for your readers.

Thank you for helping us get this right.',
                'Good morning,
thank you. That is very helpful.

We will make the changes you asked for and return the article quickly. Please tell me if anything else should be adjusted.',
                'Hello,
of course. I am grateful for the comments.

The revised version will be sent shortly. Our aim is still to add value to your website and to follow your editorial style.',
            ],
        ],
        [
            'title' => 'Follow-up · did you receive',
            'category' => 'follow_up',
            'sad' => true,
            'bodies' => [
                'Hello,
I hope you are well. I am sorry to follow up.

I would be very grateful if you had a moment to look at my last message. We would still love to work with you, even with our limited advertising budget :( and we remain ready to deliver quality articles and to pay immediately after publication, or within 24 hours.

Thank you so much for your time.',
                'Good morning,
I hope I am not disturbing you.

I am just checking whether my previous email arrived. If you had a chance to consider a more flexible rate for the three articles, I would be thankful :( We can still provide original content and pay quickly after publication.',
                'Hello,
a short note only, with my apologies for the extra message.

If you have had time to think about our request, I would be grateful for a reply, even a brief one. Our budget is limited :( but we are serious about quality and about paying within 24 hours after the articles are live.

Thank you.',
            ],
        ],
        [
            'title' => 'Extra fees · we write ourselves',
            'category' => 'offer',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you for the information.

We will take care of the writing on our side, with native-speaking authors, so I hope the extra writing fee would not apply. The articles would also be general, informative content — not casino, CBD, crypto, or 18+.

I would be grateful if we could keep the price as accessible as possible for three standard articles. Our advertising budget is very limited :(

We can pay by PayPal or bank transfer immediately after publication, or within 24 hours.',
                'Hello,
thank you.

Please note that we provide the articles ourselves, original and tailored to your audience. There is no need for your writing service in this case.

The topics will be suitable general content. I kindly ask to stay on the standard advertorial rate, with the best discount you can offer for three items :(

Payment after publication can be PayPal or bank transfer, immediately or within 24 hours.',
                'Hello,
thank you for the extra details.

We do not need the paid writing add-on, and we will not send restricted niches. I hope this makes a more flexible package price easier to offer :(

I look forward to your best rate for three general articles.',
            ],
        ],
        [
            'title' => 'When / PayPal',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you for the confirmation.

PayPal is perfectly fine for us. Bank transfer is also fine if you ever prefer it. We can pay immediately after publication, or within 24 hours.

Our team is working on the articles. As soon as I receive them, I will forward them to you. I will do my best to send them as promptly as possible.

Thank you again.',
                'Hello,
yes, we can pay via PayPal. We can also pay by bank transfer. Whichever is easier for you. The payment will be sent as soon as the articles are online, or within 24 hours.

Regarding the timing: the writers are working on the items now. I will send them to you as soon as they are ready.

Thank you.',
                'Hello,
thank you.

PayPal works for us. Please send the PayPal address when you wish. We do not delay payment after publication.

I cannot give an exact hour yet, but the articles are in progress and I will transfer them to you as soon as the team delivers them.',
            ],
        ],
        [
            'title' => 'Articles this month',
            'category' => 'reply',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you for your question.

Our team is working on the items. As soon as I receive them from them, I will forward them to you.

Thank you for your patience. I will keep you updated.',
                'Hello,
thank you for checking.

Yes, we are aiming to send them as soon as they are ready. The editorial team is working on them now, and I will forward everything the moment I have the final versions.

I appreciate your patience.',
                'Hello,
thank you.

I am following up with the writers so we can send the three articles as promptly as possible. I will write to you again as soon as they are ready for publication.

Thank you for waiting.',
            ],
        ],
        [
            'title' => '3 articles · counter €50',
            'category' => 'offer',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you for your email. I appreciate your willingness to cooperate.

I understand your previous offer, but I would like to kindly reiterate my proposal. I can pay €50 per article, so €150 for the publication of three articles :( Our advertising budget is very, very limited.

The content we will provide is 100% unique, fresh, and well-written in Danish, prepared for your readers so that it adds real value to your website.

I hope we can find a mutually beneficial arrangement. We can pay by PayPal or bank transfer immediately after publication, or within 24 hours.

Looking forward to your response.',
                'Hello,
thank you. I am grateful you are still open to working with us.

I have to ask once more, with respect: would €50 per article be possible (€150 for the three publications)? This is what our advertising budget can manage :(

The three articles would be original, written in Danish, and tailored to your audience — unique, fresh, and well-written, not copied.

If you can accept this, we will proceed quickly and pay right after publication, or within 24 hours, by PayPal or bank transfer.

I hope this meets your expectations.',
                'Hello,
thank you for your reply.

I understand your rate, and I do not want to undervalue your website. At the same time, our advertising budget is extremely limited :( The most I can offer for three articles is €150 in total (€50 each).

The content will be 100% unique and well-written in Danish for your readers.

If this is acceptable, I would be delighted to continue. Please let me know.',
            ],
        ],
        [
            'title' => 'Homepage text link or banner · 1 year',
            'category' => 'offer',
            'sad' => true,
            'bodies' => [
                'Hello,
thank you for your email. I appreciate your willingness to cooperate.

I understand your previous offer, but I would like to reiterate my proposal. I can pay €50 per article and €150 for the publication of 3 articles :( The content I will provide is 100% unique, fresh and well-written in Danish.

Regarding the placement of a text link on the website for one year, I can offer €80. Regarding the banner / text link on the website, I can only offer €80 as well :( It is important that the placement is visible on the homepage only, and not on other pages of the site.

I hope we can find a mutually beneficial arrangement. Please let me know if this meets your expectations.

Looking forward to your response.',
                'Hello,
thank you.

I am also interested in placing only a text link or a banner on the website for one year. I can only pay €120 for a banner / text link :( Our advertising budget is very limited.

Here is an example of a text link: [attach the textlink image]

You can place it on the homepage only, so it is not visible on any other pages of the website. The admin may choose the exact place on the homepage.

Let me know if we can collaborate. That would be very kind of you.',
                'Hello,
thank you for your time.

Besides the articles, I would also like to ask if a small homepage placement might be possible for one year: a text link or a banner.

I can only offer €80 for this :( because our advertising budget is very limited. The important point is that it should be visible on the main page / homepage only — not on any other pages.

We can pay by PayPal or bank transfer after it is live, or within 24 hours.

I hope we can work something out.',
            ],
        ],
        [
            'title' => 'Banner not possible · text link or logo',
            'category' => 'offer',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you for letting me know.

I fully understand if a banner, or a large banner, is not possible on your website.

In that case, would you be so kind as to place only a text link on the homepage for one year? If a text link is also not possible, we would be grateful if you could place just our logo.

It should be visible on the homepage only, not on other pages. You may choose the place.

I hope this is easier for you. Please let me know what you can do.',
                'Hello,
thank you. I understand the limitation.

If you cannot place a banner, I would like to kindly ask for a text link only, on the homepage, for one year.

If even a text link is not possible, a small logo on the homepage would also be perfectly fine for us.

The main point is that it remains on the homepage only, not on inner pages.

Please tell me what you are able to offer. I would be very grateful.',
                'Hello,
thank you for explaining.

We do not need a large banner. A text link would be enough. If that is not possible either, please just place our logo on the homepage.

Homepage only — not visible on other pages of the site. One year.

I hope this can work for you.',
            ],
        ],
        [
            'title' => 'Banner size · we can make any size or logo',
            'category' => 'offer',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you for your question.

The banner can be a small square banner, or you may choose whatever size you can place. Please let me know the size that works for your homepage, and we will prepare a new banner for you.

You can also place only our logo if that is easier. We are ok with that.

It should be visible on the homepage only, not on other pages, for one year.

Looking forward to your reply.',
                'Hello,
thank you.

Please tell me the size you can accept on the homepage. We can prepare a small square banner, or any other size you prefer.

If a banner is difficult, you may place only our logo, or a text link. We are flexible.

Homepage only, for one year.',
                'Hello,
thank you for asking about the size.

We can follow your format: a small square, a smaller banner, or only a logo. If you send the width and height you can use, we will make a new file for you.

A text link is also fine if that is simpler.

Please keep it on the homepage only, not on other pages.',
            ],
        ],
        [
            'title' => 'Where to place · any homepage spot including footer',
            'category' => 'offer',
            'sad' => false,
            'bodies' => [
                'Hello,
thank you for your message.

If there is little space, that is ok. You may place it anywhere on the homepage — even in the footer. We are ok with that.

The main point is that it should be visible on the main page / homepage only, and not on any other pages of the website. You can choose the place so that it does not appear on inner pages.

We can place this for one year.

Please let me know if this works for you.',
                'Hello,
thank you.

You do not need to find a large space. Any place on the homepage is fine for us, including the footer.

We only ask that it is not visible on other pages of the site — homepage only, for one year. You may choose the position in the way that is easiest for you.

I hope this helps.',
                'Hello,
thank you for asking where to put it.

Please choose any spot on the homepage that you prefer. The footer is also perfectly acceptable.

The important rule is only this: homepage / main page only, not the rest of the website.

One year. We will follow your layout.',
            ],
        ],
    ];
    array_splice($groups, 2, 0, email_campaign_office_country_sample_groups());
    $items = [];
    $sort = 0;
    foreach ($groups as $g) {
        foreach (['A', 'B', 'C'] as $i => $letter) {
            $body = trim((string) ($g['bodies'][$i] ?? ''));
            if ($body === '') {
                continue;
            }
            if (!str_ends_with($body, $sign)) {
                $body .= "\n\n" . $sign;
            }
            $sort++;
            $items[] = [
                'title' => (string) $g['title'] . ' · ' . $letter,
                'category' => (string) $g['category'],
                'sad' => (bool) $g['sad'],
                'sort' => $sort,
                'body' => $body,
            ];
        }
    }
    return $items;
}

/**
 * Create the office library project and insert missing English proposal cards.
 * Existing titles are left unchanged so team edits are not overwritten.
 *
 * @return array{ok:bool,project_id:int,inserted:int,skipped:int}
 */
function ensure_email_campaign_office_proposal_drafts(): array
{
    ensure_email_campaign_schema();
    $name = email_campaign_office_proposal_project_name();
    $projectId = create_email_campaign_project($name, 0, true);
    set_email_campaign_project_team_visible($projectId, true);
    $existing = list_email_campaign_drafts($projectId);
    $byTitle = [];
    foreach ($existing as $row) {
        $byTitle[mb_strtolower(trim((string) ($row['title'] ?? '')))] = (int) ($row['id'] ?? 0);
    }
    $inserted = 0;
    $skipped = 0;
    foreach (email_campaign_office_proposal_catalog() as $item) {
        $key = mb_strtolower(trim((string) $item['title']));
        if (isset($byTitle[$key])) {
            $skipped++;
            continue;
        }
        $html = email_campaign_draft_plain_to_html((string) $item['body']);
        $result = save_email_campaign_draft(
            $projectId,
            (string) $item['title'],
            $html,
            (string) $item['category'],
            0,
            0,
            ''
        );
        if (!empty($result['ok'])) {
            $inserted++;
            $id = (int) ($result['id'] ?? 0);
            if ($id > 0) {
                db()->prepare(
                    'UPDATE email_campaign_drafts SET sort_order=? WHERE id=? AND project_id=?'
                )->execute([(int) $item['sort'], $id, $projectId]);
            }
        }
    }
    return [
        'ok' => true,
        'project_id' => $projectId,
        'inserted' => $inserted,
        'skipped' => $skipped,
    ];
}

