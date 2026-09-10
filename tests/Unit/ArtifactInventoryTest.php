<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\CollectionReconciliation\ArtifactInventory;
use App\Services\CollectionReconciliation\ArtifactProvenance;
use PHPUnit\Framework\TestCase;

class ArtifactInventoryTest extends TestCase
{
    public function test_missing_first_segment_is_additive_and_changed_message_identity_is_replacement(): void
    {
        $before = ArtifactInventory::load($this->xml('<segment number="2" bytes="20"> Second@ID </segment>'));
        $added = ArtifactInventory::load($this->xml('<segment bytes="10" number="1">First@ID</segment><segment number="2" bytes="20">&lt;Second@ID&gt;</segment>'));
        $this->assertSame('additive', $added->classifyAgainst($before));
        $changed = ArtifactInventory::load($this->xml('<segment number="2" bytes="20">second@ID</segment>'));
        $this->assertSame('replacement', $changed->classifyAgainst($before));
        $serialized = ArtifactInventory::load($this->xml('<segment bytes="20" number="2">&lt;Second@ID&gt;</segment>'));
        $this->assertSame('serialization', $serialized->classifyAgainst($before));
    }

    public function test_duplicate_envelopes_and_conflicting_segment_numbers_cannot_be_additive(): void
    {
        $xml = $this->xml('<segment number="1" bytes="20">one@ID</segment>');
        $before = ArtifactInventory::load($xml);
        $file = substr($xml, strpos($xml, '<file '), strpos($xml, '</file>') + 7 - strpos($xml, '<file '));
        $duplicate = ArtifactInventory::load(str_replace('</nzb>', $file.'</nzb>', $xml));
        $this->assertSame('replacement', $duplicate->classifyAgainst($before));
        $contradiction = ArtifactInventory::load(str_replace('</segments>', '<segment number="1" bytes="20">other@ID</segment></segments>', $xml));
        $this->assertSame('replacement', $contradiction->classifyAgainst($before));
    }

    public function test_a_connected_imported_component_stays_wholly_unproved_with_an_opaque_member(): void
    {
        $supported = str_replace('An ordinary file (1/2)', '[01/03] - &quot;One.mkv&quot; yEnc (1/1)', $this->xml('<segment number="1" bytes="20">one@ID</segment>'));
        $opaqueFile = '<file subject="opaque" poster="Exact Poster" date="1767268800"><groups><group>example.group</group></groups><segments><segment number="1" bytes="1">opaque@ID</segment></segments></file>';
        $target = ArtifactInventory::load(str_replace('</nzb>', $opaqueFile.'</nzb>', $supported));
        $keys = array_keys($target->files());
        $provenance = ['files' => [$keys[0] => ['source:original'], $keys[1] => ['operation:import']],
            'components' => [['source:original', 'operation:import']]];
        $this->assertSame([], $target->proofCandidates($provenance));
        $provenance['components'] = [];
        $this->assertCount(1, $target->proofCandidates($provenance));
        $advanced = ArtifactProvenance::advance($provenance, $target, $target, 'serialize', 'serialization');
        $this->assertSame($provenance, $advanced['provenance']);
    }

    private function xml(string $segments): string
    {
        return '<nzb xmlns="http://www.newzbin.com/DTD/2003/nzb"><head><meta type="opaque">keep me</meta></head><file subject="An ordinary file (1/2)" poster="Exact Poster" date="1767268800"><groups><group>example.group</group></groups><segments>'.$segments.'</segments></file></nzb>';
    }
}
