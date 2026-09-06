<?php

/*
|--------------------------------------------------------------------------
| Legal identity
|--------------------------------------------------------------------------
|
| Who operates the Service, under which law, and where to write. The Privacy
| Policy and Terms of Service pages render from this block so the operator's
| identity is stated in exactly one place.
|
| TODO before launch: fill in LEGAL_OPERATOR_NAME, LEGAL_OPERATOR_ADDRESS,
| LEGAL_OPERATOR_NIP and LEGAL_OPERATOR_REGON in .env. The pages omit each
| registration detail that is left blank rather than printing an empty line,
| so they render correctly either way -- but a Polish JDG must publish its
| business address and NIP, so an unfilled block is a launch blocker, not a
| cosmetic one.
|
*/

return [

    /*
     * The trader operating the Service. FlexPick is run as a Polish
     * sole proprietorship (jednoosobowa dzialalnosc gospodarcza) entered in
     * CEIDG -- so the legal operator is a named individual, not a company.
     */
    'operator' => [
        'name' => env('LEGAL_OPERATOR_NAME', ''),
        'form' => 'a sole proprietorship (jednoosobowa działalność gospodarcza) registered in the Polish CEIDG register',
        'address' => env('LEGAL_OPERATOR_ADDRESS', ''),
        'nip' => env('LEGAL_OPERATOR_NIP', ''),
        'regon' => env('LEGAL_OPERATOR_REGON', ''),
    ],

    'country' => 'Poland',
    'governing_law' => 'the laws of the Republic of Poland',

    /*
     * GDPR Art. 77 -- the authority a data subject may complain to. Ours is
     * Poland's, because that is where the controller is established; a data
     * subject may also complain to the authority of their own country.
     */
    'supervisory_authority' => 'the President of the Personal Data Protection Office (Prezes Urzędu Ochrony Danych Osobowych), ul. Stawki 2, 00-193 Warsaw, Poland',

    /*
     * One address for privacy requests, legal notices and support. Kept
     * separate from app.support_email, which an operator can repoint from the
     * admin settings screen -- a legal notice address should not move by
     * accident.
     */
    'contact_email' => env('LEGAL_CONTACT_EMAIL', 'info@flexpick.net'),

    /*
     * Shown as "Last updated" on both pages. Bump it whenever the text
     * changes materially -- the Terms promise notice of material changes.
     */
    'effective_date' => '2026-09-06',

    /*
     * Named in the Privacy Policy because sending customer code to a third
     * party is the single disclosure a customer most needs to know about.
     */
    'ai_provider' => 'Anthropic PBC (United States)',
];
