import { useEffect, useState } from "react";
import { Box, Container, Center, Text, TextInput, Breadcrumbs, Modal, Button, Group, Grid, Checkbox } from '@mantine/core';
import { useNavigate } from "react-router-dom";
import { Link as RouterLink } from 'react-router-dom';
import { Header } from "../../components/Header";
import { Footer } from "../../components/Footer";
import { getConfig } from "../../utils";
import useFetch from "../../hooks/useFetch";
import { FileUploader } from "../../components/FileUploader";
import { Announcement, Row, User } from "../../types/types";
import { PageBuilder } from "../../components/PageBuilder";
import { IconEye, IconSend } from "@tabler/icons-react";
import { PagePreview } from "../../components/PagePreview";
import { StaffSelector } from "../../components/StaffSelector";
import { UserCombobox } from "../../components/UserCombobox";
import { DateTimePicker } from "@mui/x-date-pickers";
import dayjs from "dayjs";


// Generate unique ID
const generateId = () => `id-${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;

export function Create() {
  const api = useFetch();
  const [errors, setErrors] = useState({})
  const [data, setData] = useState<Announcement>({
    id: 0,
    username: "",
    subject: "",
    message: "",
    timecreated: "",
    timemodified: "",
    timestart: "",
    timeend: "",
    deleted: false,
    forcesend: false,
    attachments: "",
    existingattachments: [],
    uploadedimages: "",
    impersonate: [] as User[],
  })
  const [sendAsOptions, setSendAsOptions] = useState<User[]>([])
  // Initialize page builder with a single row and single block
  const [pageBuilderRows, setPageBuilderRows] = useState<Row[]>([
    {
      id: generateId(),
      blocks: [{
        id: generateId(),
        type: 'text',
        content: '',
      }],
    }
  ])
  const [preview, setPreview] = useState(false)
  const navigate = useNavigate()
  document.title = 'Post Announcement'

  useEffect(() => {
    getSendAsOptions()
  }, []);
  
  const getSendAsOptions = async () => {
    const fetchResponse = await api.call({
      query: {
        methodname: 'local_announcements2-get_sendas_options'
      }
    })
    if (fetchResponse.error) {
      return
    }
    setSendAsOptions(fetchResponse.data)
  }

  const handleSubmit = async (event: React.FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError("")

    // Serialize page builder rows to JSON string for storage in message field
    const serializedRows = JSON.stringify(pageBuilderRows);
    const updatedData = { ...data, message: serializedRows };

    console.log(updatedData)
    return

    let formData = JSON.parse(JSON.stringify(updatedData))


    const response = await api.call({
      method: "POST",
      body: {
        methodname: 'local_announcements2-post_announcement',
        args: formData,
      }
    })

    if (response.error) {
      setError(response.exception?.message ?? "Error submitting form")
      return
    }

    // On success, redirect to new ID if created.
    navigate('/' + response.data.id, {replace: true})
  }


  const updateField = (field: keyof Announcement, value: any) => {
    setData((state: Announcement) => ({
      ...state,
      [field]: value
    }))
  }

  const updateAttachments = (value: string) => {
    updateField('attachments', value)
  }

  // Only staff should be able to post/edit announcements
  if (!getConfig().roles?.includes("staff")) {
    return ""
  }

  return (
    <>
      <Header />
      <div style={{minHeight: 'calc(100vh - 154px)'}}>

        { error.length 
          ? <Container size="xl">
              <Center h={300}>
                <Text fw={600} fz="lg">Failed to post announcement...</Text>
                <Text c="red" mt="md">{error}</Text>
              </Center>
            </Container> 
          : <>
              <Container size="xl">
                <div className="page-header">
                  <Container size="xl" my="md" className="relative p-0 ps-7">
                      <Breadcrumbs fz="sm" mb="sm">
                        <RouterLink to="/">
                          <Text c="blue">Announcements</Text>
                        </RouterLink>
                        <Text c="gray.6">New</Text>
                      </Breadcrumbs>
                  </Container>
                </div>
              </Container>
              <Container size="xl" my="md">
                <form noValidate onSubmit={handleSubmit}>

                <Grid grow>
                <Grid.Col span={{ base: 12, lg: 9 }}>
                  <Box className="flex flex-col gap-4 relative ps-7 pe-7">

                
                    <div>
                      <Text fz="sm" fw={500} c="#212529" mb="xs">Subject</Text>
                      <TextInput
                        value={data.subject}
                        onChange={(e) => setData({...data, subject: e.target.value})}
                      />
                    </div>

                    <div>
                      <Group justify="space-between" className="mb-1">
                        <Text fz="sm" fw={500} c="#212529">Message</Text>
                        
                      </Group>
                      <PageBuilder 
                        rows={pageBuilderRows} 
                        onChange={setPageBuilderRows}
                      />
                    </div>

                    <div>
                      <Text fz="sm" fw={500} c="#212529" mb="xs">Attachments</Text>
                      <div className="border">
                        <FileUploader 
                          desc={`Drag and drop files here...`} 
                          maxFiles={5} 
                          maxSize={10} 
                          existingfiles={[]} 
                          setState={updateAttachments} 
                        />
                      </div>
                    </div>


                



                  </Box>
                  </Grid.Col>
                  <Grid.Col span={{ base: 12, lg: 3 }}>

                    <div className="flex flex-col gap-4">
                      { sendAsOptions.length > 0 && ( 
                          <UserCombobox
                            value={data.impersonate && data.impersonate.length > 0 ? data.impersonate[0] : null}
                            onChange={(value) => setData({...data, impersonate: value ? [value] : []})}
                            options={sendAsOptions}
                            label="Send as"
                            description="Send as another user or leave blank to send as yourself"
                            placeholder="Select user..."
                          />
                      )}

                      <div>
                        <Text fz="sm" fw={500} c="#212529">Send immediately</Text>
                        <Checkbox
                          label="Send a copy of this announcement immediately. This should only be used for emergencies."
                          checked={data.forcesend}
                          onChange={(e) => setData({...data, forcesend: e.target.checked})}
                          c="red"
                        />
                      </div>


                      <div>
                        <Text fz="sm" mb="5px" fw={500} c="#212529">Start time</Text>
                        <DateTimePicker
                          value={dayjs.unix(Number(data.timestart))}
                          onChange={(newValue) => updateField('timestart', (newValue?.unix() ?? 0).toString())}
                          views={['day', 'month', 'year', 'hours', 'minutes']}
                          slotProps={{
                            textField: {
                              error: !!errors.timestart,
                            },
                          }}
                          readOnly={viewStateProps.readOnly}
                        />
                      </div>
                      <div>
                        <Text fz="sm" mb="5px" fw={500} c="#212529">End time</Text>
                        <DateTimePicker 
                          value={dayjs.unix(Number(formData.timeend))}
                          onChange={(newValue) => {
                            manuallyEdited.current = true;
                            updateField('timeend', (newValue?.unix() ?? 0).toString());
                          }}
                          views={['day', 'month', 'year', 'hours', 'minutes']}
                          slotProps={{
                            textField: {
                              error: !!errors.timestart,
                            },
                          }}
                          readOnly={viewStateProps.readOnly}
                        />
                      </div>

                      
                    


                    Availability dates...
                    Save draft / Publish buttons...

                    
                    </div>

                    <div className="flex gap-2">
                      <Button variant="light" size="compact-md" leftSection={<IconEye size={14} />} onClick={() => setPreview(true)}>
                        Preview
                      </Button>
                      <Button 
                        type="submit"
                        variant="primary"
                        size="compact-md"
                        leftSection={<IconSend size={14} />}
                      >
                        Post
                      </Button>
                    </div>
                  </Grid.Col>
                  </Grid>
                </form>
              </Container>
            </> 
        }

      </div>
      <Footer />

      {preview && (
        <Modal opened={preview} onClose={() => setPreview(false)} title="Preview" size="700px">
          <Text fz="lg" fw={500} c="#212529">{data.subject}</Text>
          <PagePreview rows={pageBuilderRows} />
        </Modal>
      )}
    </>
  );
}