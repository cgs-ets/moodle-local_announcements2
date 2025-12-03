import { Box, Modal, Button } from '@mantine/core';
import { Editor as TinyMCEEditor } from '@hugerte/hugerte-react';
import { useRef, useState, useEffect } from 'react';
import { FileUploader } from "./FileUploader";
import { getConfig } from '../utils';
import { Announcement } from '../types/types';

type EditorParams = {
  data: any,
  setData: (data: any) => void
}

export function Editor({ data, setData }: EditorParams) {
  const editorRef = useRef<any>(null);
  const [imageModalOpened, setImageModalOpened] = useState(false);
  const [insertedImageKeys, setInsertedImageKeys] = useState<Set<string>>(new Set());

  const updateImageUploads = (value: string) => {
    setData((state: Announcement) => ({...state, uploadedimages: value}));
    // Parse the uploaded images and insert them into the editor
    if (value && editorRef.current) {
      const editor = editorRef.current;
      const imageEntries = value.split(',').filter(entry => entry.startsWith('NEW::'));
      imageEntries.forEach(entry => {
        const filename = entry.replace('NEW::', '');
        // Only insert if we haven't inserted this image yet
        if (!insertedImageKeys.has(filename)) {
          const imageUrl = getConfig().wwwroot + '/local/announcements2/upload.php?view=1&fileid=' + encodeURIComponent(filename) + '&sesskey=' + getConfig().sesskey;
          editor.execCommand('mceInsertContent', false, `<img src="${imageUrl}" alt="" />`);
          setInsertedImageKeys(prev => new Set([...prev, filename]));
        }
      });
    }
  };

  const handleImageModalClose = () => {
    setImageModalOpened(false);
    //setData({...data, uploadedimages: ""});
  };

  // Custom image button handler
  const handleImageButtonClick = () => {
    setImageModalOpened(true);
  };

  return (
    <Box>
      <TinyMCEEditor
        onInit={(_evt: any, editor: any) => editorRef.current = editor}
        value={data.message || ''}
        onEditorChange={(content: string) => {
          setData((state: Announcement) => ({...state, message: content}));
        }}
        init={{
          height: 500,
          menubar: false,
          plugins: [
            'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
            'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
            'insertdatetime', 'media', 'table', 'code', 'help', 'wordcount'
          ],
          toolbar: 'undo redo | blocks | ' +
            'bold italic forecolor | alignleft aligncenter ' +
            'alignright alignjustify | bullist numlist outdent indent | ' +
            'removeformat | link customimage table | code',
          content_style: 'body { font-family: Inter, -apple-system, BlinkMacSystemFont, San Francisco, Segoe UI, Roboto, Helvetica Neue, sans-serif; font-size: 14px; }',
          branding: false,
          promotion: false,
          resize: true,
          // Self-hosted configuration - GPL license is automatic when using local package
          table_toolbar: 'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | tableinsertcolbefore tableinsertcolafter tabledeletecol | tablemergecells tablesplitcells',
          table_resize_bars: true,
          table_default_attributes: {
            border: '1'
          },
          table_default_styles: {
            'border-collapse': 'collapse',
            'width': '100%'
          },
          // Custom image button
          setup: (editor: any) => {
            editor.ui.registry.addButton('customimage', {
              text: 'Upload Image',
              icon: 'image',
              onAction: handleImageButtonClick
            });
          },
          // Image handling
          images_upload_handler: async (_blobInfo: any, _progress: any) => {
            // This can be used for direct image uploads if needed
            return new Promise((_resolve, reject) => {
              // For now, we'll use the modal approach
              reject('Please use the Upload Image button');
            });
          },
          // Link settings
          link_assume_external_targets: true,
          link_default_target: '_blank',
          // Paste settings
          paste_as_text: false,
          paste_auto_cleanup_on_paste: true,
          paste_remove_styles: true,
          paste_remove_styles_if_webkit: true,
          paste_strip_class_attributes: 'all',
        }}
      />

      <Modal
        opened={imageModalOpened}
        onClose={handleImageModalClose}
        title="Upload Image"
        size="lg"
      >
        <FileUploader 
          desc={`Drag and drop image files here...`} 
          maxFiles={1} 
          maxSize={10} 
          existingfiles={[]} 
          setState={updateImageUploads} 
        />
        <Button 
          mt="md" 
          onClick={handleImageModalClose}
          fullWidth
        >
          Done
        </Button>
      </Modal>
    </Box>
  );
}
