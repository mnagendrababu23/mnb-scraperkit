<?php

declare(strict_types=1);

namespace Mnb\ScraperKit\Source\Plos;

/**
 * Known PLOS journal source metadata.
 *
 * This catalog is intentionally local/static so MNB ScraperKit can generate
 * useful API/feed commands even when the protected marketing page cannot be
 * crawled through static PHP HTTP mode.
 */
final class PlosJournalCatalog
{
    /**
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        return [
            $this->journal('plosone', 'PLOS ONE', 'PLOS ONE', 'https://journals.plos.org/plosone/', '1932-6203', ['journal.pone'], ['https://journals.plos.org/plosone/feed/atom', 'https://www.plosone.org/taxonomy']),
            $this->journal('plosbiology', 'PLOS Biology', 'PLOS Biology', 'https://journals.plos.org/plosbiology/', null, ['journal.pbio'], ['https://feeds.plos.org/plosbiology/NewArticles', 'https://journals.plos.org/plosbiology/feed/atom']),
            $this->journal('plosmedicine', 'PLOS Medicine', 'PLOS Medicine', 'https://journals.plos.org/plosmedicine/', null, ['journal.pmed'], ['https://feeds.plos.org/plosmedicine/NewArticles', 'https://journals.plos.org/plosmedicine/feed/atom']),
            $this->journal('ploscompbiol', 'PLOS Computational Biology', 'PLOS Computational Biology', 'https://journals.plos.org/ploscompbiol/', null, ['journal.pcbi'], ['https://feeds.plos.org/ploscompbiol/NewArticles', 'https://journals.plos.org/ploscompbiol/feed/atom']),
            $this->journal('plosgenetics', 'PLOS Genetics', 'PLOS Genetics', 'https://journals.plos.org/plosgenetics/', null, ['journal.pgen'], ['https://feeds.plos.org/plosgenetics/NewArticles', 'https://journals.plos.org/plosgenetics/feed/atom']),
            $this->journal('plospathogens', 'PLOS Pathogens', 'PLOS Pathogens', 'https://journals.plos.org/plospathogens/', null, ['journal.ppat'], ['https://feeds.plos.org/plospathogens/NewArticles', 'https://journals.plos.org/plospathogens/feed/atom']),
            $this->journal('plosntds', 'PLOS Neglected Tropical Diseases', 'PLOS Neglected Tropical Diseases', 'https://journals.plos.org/plosntds/', null, ['journal.pntd'], ['https://journals.plos.org/plosntds/feed/atom']),
            $this->journal('plosglobalpublichealth', 'PLOS Global Public Health', 'PLOS Global Public Health', 'https://journals.plos.org/globalpublichealth/', null, ['journal.pgph'], ['https://journals.plos.org/globalpublichealth/feed/atom']),
            $this->journal('plosdigitalhealth', 'PLOS Digital Health', 'PLOS Digital Health', 'https://journals.plos.org/digitalhealth/', null, ['journal.pdig'], ['https://journals.plos.org/digitalhealth/feed/atom']),
            $this->journal('plosclimate', 'PLOS Climate', 'PLOS Climate', 'https://journals.plos.org/climate/', null, ['journal.pclm'], ['https://journals.plos.org/climate/feed/atom']),
            $this->journal('ploswater', 'PLOS Water', 'PLOS Water', 'https://journals.plos.org/water/', null, ['journal.pwat'], ['https://journals.plos.org/water/feed/atom']),
            $this->journal('plossustainability', 'PLOS Sustainability and Transformation', 'PLOS Sustainability and Transformation', 'https://journals.plos.org/sustainabilitytransformation/', null, ['journal.pstr'], ['https://journals.plos.org/sustainabilitytransformation/feed/atom']),
            $this->journal('ploscomplexsystems', 'PLOS Complex Systems', 'PLOS Complex Systems', 'https://journals.plos.org/complexsystems/', null, ['journal.pcsy'], ['https://journals.plos.org/complexsystems/feed/atom']),
            $this->journal('plosmentalhealth', 'PLOS Mental Health', 'PLOS Mental Health', 'https://journals.plos.org/mentalhealth/', null, ['journal.pmen'], ['https://journals.plos.org/mentalhealth/feed/atom']),
            $this->journal('plosaginghealth', 'PLOS Aging and Health', 'PLOS Aging and Health', 'https://journals.plos.org/aginghealth/', null, ['journal.pagh'], ['https://journals.plos.org/aginghealth/feed/atom']),
            $this->journal('plosecosystems', 'PLOS Ecosystems', 'PLOS Ecosystems', 'https://journals.plos.org/ecosystems/', null, ['journal.peco'], ['https://journals.plos.org/ecosystems/feed/atom']),
        ];
    }

    /** @return array<string,mixed> */
    public function find(string $keyOrName): ?array
    {
        $needle = $this->normalizeKey($keyOrName);
        foreach ($this->all() as $journal) {
            $keys = [
                $journal['key'] ?? '',
                $journal['name'] ?? '',
                $journal['api_journal'] ?? '',
                basename(trim((string) ($journal['homepage'] ?? ''), '/')),
            ];
            foreach ($keys as $key) {
                if ($this->normalizeKey((string) $key) === $needle) {
                    return $journal;
                }
            }
        }
        return null;
    }

    /** @return array<int,string> */
    public function keys(): array
    {
        return array_map(static fn (array $j): string => (string) $j['key'], $this->all());
    }

    /** @return array<string,mixed> */
    private function journal(string $key, string $name, string $apiJournal, string $homepage, ?string $issn, array $doiPrefixes, array $feedCandidates): array
    {
        return [
            'key' => $key,
            'name' => $name,
            'api_journal' => $apiJournal,
            'homepage' => $homepage,
            'issn' => $issn,
            'doi_prefixes' => $doiPrefixes,
            'feed_candidates' => $feedCandidates,
            'api_query_example' => 'journal:"' . $apiJournal . '"',
        ];
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = str_replace(['&', '+'], 'and', $value);
        return preg_replace('/[^a-z0-9]+/', '', $value) ?: '';
    }
}
