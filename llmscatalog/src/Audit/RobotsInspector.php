<?php
namespace LlmsCatalog\Audit;

/** Small robots.txt parser for deterministic policy evidence. */
final class RobotsInspector
{
    public function rules(string $text): array
    {
        $groups=[]; $group=null;
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line=trim(explode('#',$line,2)[0]); if ($line==='' || strpos($line,':')===false) continue;
            [$name,$value]=array_map('trim',explode(':',$line,2)); $name=strtolower($name);
            if ($name==='user-agent') { if ($group===null || $group['rules']) { $groups[]=['agents'=>[],'rules'=>[]]; $group=count($groups)-1; } $groups[$group]['agents'][]=strtolower($value); }
            elseif (in_array($name,['allow','disallow','sitemap'],true) && $group!==null) $groups[$group]['rules'][]=['directive'=>$name,'value'=>$value];
        }
        return $groups;
    }
    public function access(string $text, string $agent, string $path): ?bool
    {
        $best=null; $decision=null;
        foreach ($this->rules($text) as $group) {
            $matched=false; foreach ($group['agents'] as $ua) if ($ua==='*' || stripos($agent,$ua)!==false) $matched=true;
            if (!$matched) continue;
            foreach ($group['rules'] as $rule) { if (!in_array($rule['directive'],['allow','disallow'],true) || $rule['value']==='') continue; if (str_starts_with($path,$rule['value']) && strlen($rule['value']) >= (int)$best) { $best=strlen($rule['value']); $decision=$rule['directive']==='allow'; } }
        }
        return $decision;
    }
}
