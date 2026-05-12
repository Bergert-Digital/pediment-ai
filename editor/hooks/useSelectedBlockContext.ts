import { useSelect } from '@wordpress/data';

export type SelectedBlock = {
  clientId: string;
  name: string;
  attributes: Record<string, any>;
  innerBlocks: any[];
};

export default function useSelectedBlockContext(): SelectedBlock | null {
  return useSelect((s) => {
    const bs = s('core/block-editor') as any;
    const clientId = bs.getSelectedBlockClientId();
    if (!clientId) return null;
    const block = bs.getBlock(clientId);
    if (!block) return null;
    return {
      clientId,
      name: block.name,
      attributes: block.attributes ?? {},
      innerBlocks: block.innerBlocks ?? [],
    };
  }, []);
}
