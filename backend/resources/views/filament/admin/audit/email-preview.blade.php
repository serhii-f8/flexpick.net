{{--
    srcdoc + a valueless sandbox attribute is the whole point of this file: the
    stored body is a complete HTML document with its own <style> block, so
    rendering it inline would bleed CSS across the admin panel and execute
    whatever the template contains. A bare `sandbox` denies scripts, forms and
    same-origin access.
--}}
<iframe
    sandbox
    srcdoc="{{ $body }}"
    title="{{ __('Email preview') }}"
    class="h-[60vh] w-full rounded-lg border border-gray-200 bg-white dark:border-gray-700"
></iframe>
