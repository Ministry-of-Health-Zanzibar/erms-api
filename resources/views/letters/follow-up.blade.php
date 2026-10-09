<!DOCTYPE html>
<html lang="{{ $language }}">
<head>
    <meta charset="UTF-8">
    <title>Follow-up letter</title>
    <style>
        @page { size: A4 portrait; margin: 6mm 9mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111; background: #fff; font-family: DejaVu Sans, Arial, sans-serif; font-size: 10pt; line-height: 1.38; }
        .letter { width: 100%; padding: 0; page-break-inside: avoid; page-break-after: avoid; }
        .logo-wrap { width: 100%; text-align: center; }
        .logo { display: block; width: auto; height: 72px; margin: 0 auto 5px; }
        .masthead { margin-bottom: 5px; text-align: center; }
        .masthead-title { margin: 0 0 1px; font-size: 11.5pt; font-weight: 700; }
        .masthead-grid { display: table; width: 100%; margin-top: 4px; text-align: left; font-size: 9.2pt; }
        .masthead-grid > div { display: table-cell; width: 50%; vertical-align: top; }
        .masthead-grid > div:last-child { text-align: right; }
        p { margin: 0 0 4px; }
        .rule { margin: 5px 0; border: 0; border-top: 1px solid #111; }
        .meta { display: table; width: 100%; margin: 5px 0 7px; font-size: 9.4pt; font-weight: 600; }
        .meta > div { display: table-cell; width: 50%; }
        .meta > div:last-child { text-align: right; }
        .recipient { margin-bottom: 8px; }
        .uppercase { text-transform: uppercase; font-weight: 700; }
        .subject { margin-bottom: 6px; text-align: center; font-weight: 700; text-transform: uppercase; }
        .body { line-height: 1.42; }
        .body p { margin-bottom: 5.5px; }
        .signature-wrap { position: relative; width: 100%; min-height: 33mm; margin-top: 3px; page-break-inside: avoid; }
        .signature-cell { display: block; width: 100%; height: 15mm; }
        .signature { position: relative; z-index: 1; display: block; width: 46mm; height: 14mm; object-fit: contain; object-position: left center; }
        .stamp-cell { position: absolute; top: 3mm; left: 34mm; z-index: 10; width: 45mm; height: 27mm; text-align: left; }
        .stamp { position: relative; z-index: 10; display: block; width: 45mm; height: 27mm; object-fit: contain; opacity: 1; }
        .signatory { position: relative; z-index: 1; margin-top: 0; }
        .signatory p { margin-bottom: 1px; }
        .footer { margin-top: 6px; text-align: center; color: #444; font-size: 8pt; }
        .footer .rule { margin: 5px 0 4px; }
    </style>
</head>
<body>
<main class="letter">
    <div class="logo-wrap"><img class="logo" src="{{ $logoData }}" alt="Government of Zanzibar"></div>

    @if ($language === 'en')
        <div class="masthead">
            <p class="masthead-title">REVOLUTIONARY GOVERNMENT OF ZANZIBAR</p>
            <p class="masthead-title">MINISTRY OF HEALTH</p>
            <div class="masthead-grid">
                <div>
                    <p>6 Barabara ya Health Office</p>
                    <p>S.L.P 236 MNANZIMMOJA</p>
                    <p>POSTIKODI 70467 Mjini Magharibi Zanzibar</p>
                </div>
                <div>
                    <p>Website: www.mohz.go.tz</p>
                    <p>Email: {{ $email }}</p>
                </div>
            </div>
        </div>

        <hr class="rule">
        <div class="meta">
            <div>Ref: CDA.25/254/01</div>
            <div>DATE: {{ $referenceDate }}</div>
        </div>

        <div class="recipient">
            <p class="uppercase">GENERAL MANAGER,</p>
            <p class="uppercase">{{ $referral?->hospital?->hospital_name ?? 'N/A' }}</p>
            <p class="uppercase">{{ $referral?->hospital?->hospital_address ?? 'N/A' }}</p>
        </div>

        <p class="subject">RE: CONTINUATION OF TREATMENT FOR {{ $referral?->patient?->name ?? 'N/A' }}<br>{{ $ageLabel }}</p>
        <div class="body">
            <p>Please refer to the subject above.</p>
            <p>The Ministry of Health Zanzibar presents the above-mentioned patient for continuation of further treatment.</p>
            <p>Through this letter, we request that you continue providing the necessary treatment to <strong>{{ $referral?->patient?->name ?? 'N/A' }}</strong> as agreed.</p>
            <p>The treatment costs will be paid by the Ministry of Health in accordance with the agreement with <strong>{{ $referral?->hospital?->hospital_name ?? 'N/A' }}</strong>.</p>
            <p>The Ministry of Health requests brief treatment progress information, including the diagnosis, treatment given, assessment, and reason for returning again.</p>
            <p>The Ministry also requests that a complete report be submitted at the end of treatment.</p>
            <p>Please keep us informed of progress.</p>
            <p><strong>Thank you.</strong></p>
        </div>

        <div class="signature-wrap">
            <div class="signature-cell"><img class="signature" src="{{ $signatureData }}" alt="Signature"></div>
            <div class="signatory">
                <p>DR. MARYAM SEIF HEMED,</p>
                <p>DIRECTOR GENERAL,</p>
                <p>MINISTRY OF HEALTH,</p>
                <p>ZANZIBAR.</p>
            </div>
            <div class="stamp-cell"><img class="stamp" src="{{ $stampData }}" alt="Official Ministry stamp"></div>
        </div>
        <div class="footer">
            <hr class="rule">
            <p><em><strong>For direct communication:</strong></em></p>
            <p>Permanent Secretary, Director General</p>
            <p>{{ $dgEmail }}</p>
        </div>
    @else
        <div class="masthead">
            <p class="masthead-title">SERIKALI YA MAPINDUZI YA ZANZIBAR</p>
            <p class="masthead-title">WIZARA YA AFYA</p>
            <div class="masthead-grid">
                <div>
                    <p>6 Barabara ya Health Office</p>
                    <p>S.L.P 236 MNANZIMMOJA</p>
                    <p>POSTIKODI 70467 Mjini Magharibi Zanzibar</p>
                </div>
                <div>
                    <p>Tovuti: www.mohz.go.tz</p>
                    <p>Barua pepe: {{ $email }}</p>
                </div>
            </div>
        </div>

        <hr class="rule">
        <div class="meta">
            <div>KUMB: CDA.25/254/01</div>
            <div>TAREHE: {{ $referenceDate }}</div>
        </div>

        <div class="recipient">
            <p class="uppercase">MKURUGENZI MTENDAJI,</p>
            <p class="uppercase">{{ $referral?->hospital?->hospital_name ?? 'N/A' }}</p>
            <p class="uppercase">{{ $referral?->hospital?->hospital_address ?? 'N/A' }}</p>
        </div>

        <p class="subject">Kuh: KUMPATIA MATIBABU MGONJWA {{ $referral?->patient?->name ?? 'N/A' }}<br>{{ $ageLabel }}</p>
        <div class="body">
            <p>Tafadhali naomba uhusike na mada ya hapo juu.</p>
            <p>Wizara ya Afya Zanzibar, inamleta kwa ajili ya kuendelea na matibabu zaidi mtajwa hapo juu.</p>
            <p>Kwa barua hii, tunaomba muendelee kumpatia matibabu stahiki mgonjwa <strong>{{ $referral?->patient?->name ?? 'N/A' }}</strong> kama tulivyokubaliana.</p>
            <p>Gharama za matibabu zitalipwa na Wizara ya Afya kwa mujibu wa Mkataba na <strong>{{ $referral?->hospital?->hospital_name ?? 'N/A' }}</strong>.</p>
            <p>Wizara ya Afya inaomba taarifa fupi za maendeleo ya matibabu kwa mhusika ikiwemo Diagnosis, Tiba aliyopewa, tathmini, na sababu ya kurudi mara nyingine.</p>
            <p>Aidha Wizara ya Afya inaomba ripoti kamili iwasilishwe mwishoni mwa matibabu.</p>
            <p>Naomba kuwasiliana kwa hatua.</p>
            <p><strong>Ahsante,</strong></p>
        </div>

        <div class="signature-wrap">
            <div class="signature-cell"><img class="signature" src="{{ $signatureData }}" alt="Sahihi"></div>
            <div class="signatory">
                <p>DKT. MARYAM SEIF HEMED,</p>
                <p>MKURUGENZI MKUU,</p>
                <p>WIZARA YA AFYA,</p>
                <p><u>ZANZIBAR</u></p>
            </div>
            <div class="stamp-cell"><img class="stamp" src="{{ $stampData }}" alt="Muhuri rasmi wa Wizara"></div>
        </div>
        <div class="footer">
            <hr class="rule">
            <p><em><strong>Kwa mawasiliano ya moja kwa moja:</strong></em></p>
            <p>Katibu Mkuu {{ $permanentSecretary }}, Mkurugenzi Mkuu barua pepe</p>
            <p>{{ $dgEmail }}</p>
        </div>
    @endif
</main>
</body>
</html>
